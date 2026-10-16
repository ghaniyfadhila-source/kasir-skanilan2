<?php

namespace App\Http\Controllers\Cashier;

use App\Events\OrderPlaced;
use App\Events\OrderVoided;
use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Transaction;
use App\Support\Rupiah;
use App\Models\TransactionItem;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    // ==================== AUTH ====================

    public function login(Request $request)
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $console = \App\Models\Console::where('device_id', $validated['device_id'])
            ->where('is_active', true)
            ->first();

        if (!$console) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid device_id or password.'
            ], 401);
        }

        // Kasir yang terkait dengan console ini
        $cashier = \App\Models\User::where('device_id', $console->device_id)
            ->where('role', 'cashier')
            ->first();

        if (!$cashier) {
            return response()->json([
                'success' => false,
                'message' => 'No cashier user linked to this console. Contact admin.'
            ], 403);
        }

        // Password Cashier Web UI bisa berupa salah satu dari:
        // 1. password konsol (console_credentials) - kata sandi perangkat
        // 2. password user kasir - yang bisa di-reset admin lewat menu Reset Password
        // Menerima keduanya supaya reset password benar-benar berguna; kalau
        // hanya password konsol yang berlaku, reset password kasir jadi sia-sia.
        $credential = $console->credentials;

        $matchesConsolePassword = $credential
            && Hash::check($validated['password'], $credential->password_hash);

        $matchesUserPassword = Hash::check($validated['password'], $cashier->password);

        if (! $matchesConsolePassword && ! $matchesUserPassword) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid device_id or password.'
            ], 401);
        }

        // Create Sanctum token
        $token = $cashier->createToken('cashier-' . $console->device_id)->plainTextToken;

        Log::channel('audit')->info('Cashier login', [
            'user_id' => $cashier->id,
            'device_id' => $console->device_id,
            'console_name' => $console->name,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'device_id' => $console->device_id,
                'console_name' => $console->name,
                'cashier_name' => $cashier->name,
            ]
        ]);
    }

    // ==================== MENU ====================

    public function menu(Request $request)
    {
        $console = $this->console($request);

        $categories = Category::with(['menuItems' => function ($q) {
            $q->where('is_active', true)
              ->where('stock', '!=', 0)
              ->orderBy('sort_order');
        }])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // Filter out categories with no available items
        $categories = $categories->filter(fn($c) => $c->menuItems->count() > 0);

        return response()->json([
            'success' => true,
            'data' => $categories->map(function ($cat) {
                return [
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'sort_order' => $cat->sort_order,
                    'items' => $cat->menuItems->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'name' => $item->name,
                            'price_cents' => $item->price_cents,
                            'price_formatted' => Rupiah::format($item->price_cents),
                            'stock' => $item->stock,
                            'is_unlimited' => $item->stock === -1,
                            'image_url' => $item->image_url ? asset('storage/' . $item->image_url) : null,
                        ];
                    })->values(),
                ];
            })->values(),
        ]);
    }

    // ==================== ORDERS ====================

    public function store(Request $request)
    {
        $console = $this->console($request);
        $cashier = $request->user();

        // Manual validation to return proper format
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'uuid', 'exists:menu_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        // Idempotensi: request ulang dengan kunci sama harus mengembalikan order
        // yang sama, bukan membuat order baru.
        $existing = Transaction::where('idempotency_key', $validated['idempotency_key'])->first();
        if ($existing) {
            return $this->orderResponse($existing, duplicate: true, status: 200);
        }

        // Validate items and calculate total
        $itemIds = collect($validated['items'])->pluck('menu_item_id');
        $menuItems = MenuItem::whereIn('id', $itemIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $totalCents = 0;
        $transactionItems = [];

        foreach ($validated['items'] as $itemData) {
            $menuItem = $menuItems->get($itemData['menu_item_id']);
            
            if (!$menuItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Menu item not found or inactive: ' . $itemData['menu_item_id']
                ], 422);
            }

            if ($menuItem->stock !== -1 && $menuItem->stock < $itemData['quantity']) {
                return response()->json([
                    'success' => false,
                    'message' => "Stok {$menuItem->name} tidak mencukupi (tersisa: {$menuItem->stock})"
                ], 422);
            }

            $subtotal = $menuItem->price_cents * $itemData['quantity'];
            $totalCents += $subtotal;

            $transactionItems[] = [
                'menu_item_id' => $menuItem->id,
                'quantity' => $itemData['quantity'],
                'unit_price_cents' => $menuItem->price_cents,
            ];
        }

        // Create transaction with awaiting_rfid status
        $transaction = DB::transaction(function () use ($console, $cashier, $totalCents, $transactionItems, $validated) {
            $transaction = Transaction::create([
                'console_id' => $console->id,
                'cashier_id' => $cashier->id,
                'total_cents' => $totalCents,
                'status' => Transaction::STATUS_AWAITING_RFID,
                'expires_at' => now()->addSeconds(Transaction::rfidTimeoutSeconds()),
                'idempotency_key' => $validated['idempotency_key'],
            ]);

            foreach ($transactionItems as $item) {
                TransactionItem::create([
                    'transaction_id' => $transaction->id,
                    ...$item,
                ]);
            }

            return $transaction;
        });

        Log::channel('audit')->info('Order created (awaiting_rfid)', [
            'transaction_id' => $transaction->id,
            'console_id' => $console->id,
            'cashier_id' => $cashier->id,
            'total_cents' => $totalCents,
            'items_count' => count($transactionItems),
        ]);

        // Ticket 09: kabari dashboard admin bahwa ada order baru yang menunggu tap.
        OrderPlaced::dispatch($transaction);

        return $this->orderResponse($transaction);
    }

    /**
     * Bentuk respons order yang konsisten untuk create maupun duplicate.
     */
    private function orderResponse(Transaction $transaction, bool $duplicate = false, int $status = 201): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'duplicate' => $duplicate,
            'data' => [
                'order_id' => $transaction->id,
                'total_cents' => $transaction->total_cents,
                'total_formatted' => Rupiah::format($transaction->total_cents),
                'status' => $transaction->status,
                'expires_at' => $transaction->expires_at?->toIso8601String(),
            ],
        ], $status);
    }

    public function status(Request $request, string $id)
    {
        $cashier = $request->user();

        $transaction = Transaction::where('id', $id)
            ->where('console_id', $this->console($request)->id)
            ->where('cashier_id', $cashier->id)
            ->with('transactionItems.menuItem', 'buyer')
            ->firstOrFail();

        $data = [
            'order_id' => $transaction->id,
            'total_cents' => $transaction->total_cents,
            'total_formatted' => Rupiah::format($transaction->total_cents),
            'status' => $transaction->status,
            'expires_at' => $transaction->expires_at?->toIso8601String(),
            'items' => $transaction->transactionItems->map(fn ($i) => [
                'name' => $i->menuItem?->name,
                'quantity' => $i->quantity,
                'unit_price_cents' => $i->unit_price_cents,
                'subtotal_cents' => $i->subtotalCents(),
            ])->values(),
        ];

        if ($transaction->isCompleted()) {
            $data['receipt_no'] = $transaction->receipt_no;
            $data['buyer_name'] = $transaction->buyer?->name;
            $data['new_balance_cents'] = $transaction->buyer?->balance_cents;
            $data['completed_at'] = $transaction->completed_at?->toIso8601String();
        }

        if ($transaction->isVoided() && $transaction->error_message) {
            $data['error_message'] = $transaction->error_message;
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function cancel(Request $request, string $id)
    {
        $cashier = $request->user();

        $transaction = Transaction::where('id', $id)
            ->where('console_id', $this->console($request)->id)
            ->where('cashier_id', $cashier->id)
            ->firstOrFail();

        // Order yang sudah dibayar tidak boleh dibatalkan di sini — dana sudah
        // keluar dari saldo pembeli. Pengembalian dana belum ada di v1.
        if (! $transaction->isAwaitingRfid()) {
            return response()->json([
                'success' => false,
                'error_code' => 'order_not_cancellable',
                'message' => 'Order tidak dapat dibatalkan karena sudah diproses.',
            ], 422);
        }

        $transaction->forceFill([
            'status' => Transaction::STATUS_VOIDED,
            'voided_at' => now(),
            'voided_by' => $cashier->id,
            'error_code' => 'CANCELLED_BY_CASHIER',
            'error_message' => 'Dibatalkan oleh kasir.',
        ])->save();

        \App\Models\AuditLog::create([
            'console_id' => $transaction->console_id,
            'user_id' => $cashier->id,
            'action' => 'transaction.voided_by_cashier',
            'metadata' => [
                'transaction_id' => $transaction->id,
                'total_cents' => $transaction->total_cents,
            ],
        ]);

        Log::channel('audit')->info('Order cancelled by cashier', [
            'transaction_id' => $transaction->id,
            'cashier_id' => $cashier->id,
        ]);

        // Ticket 09: kasir yang asal membatalkan harus diberi tahu supaya
        // halamannya tidak menggantung sampai batas waktu habis.
        OrderVoided::dispatch($transaction, 'dibatalkan kasir');

        return response()->json([
            'success' => true,
            'message' => 'Order dibatalkan.',
        ]);
    }
}