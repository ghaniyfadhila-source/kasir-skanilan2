<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        // Verify console password (stored as hash in console_credentials)
        $credential = $console->credentials;
        if (!$credential || !\Illuminate\Support\Facades\Hash::check($validated['password'], $credential->password_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid device_id or password.'
            ], 401);
        }

        // Get or create cashier user for this console
        $cashier = \App\Models\User::where('device_id', $console->device_id)
            ->where('role', 'cashier')
            ->first();

        if (!$cashier) {
            return response()->json([
                'success' => false,
                'message' => 'No cashier user linked to this console. Contact admin.'
            ], 403);
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
        $console = $request->console;

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
                            'price_formatted' => 'Rp ' . number_format($item->price_cents / 100, 0, ',', '.'),
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
        $console = $request->console;
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

        // Check idempotency
        $existing = Transaction::where('idempotency_key', $validated['idempotency_key'])->first();
        if ($existing) {
            return response()->json([
                'success' => true,
                'duplicate' => true,
                'data' => [
                    'order_id' => $existing->id,
                    'total_cents' => $existing->total_cents,
                    'status' => $existing->status,
                    'expires_at' => $existing->created_at->addSeconds(60)->toISOString(),
                ]
            ]);
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
                'status' => 'awaiting_rfid',
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

        // Broadcast to console via Realtime (will be implemented in Ticket 09)
        // For now, we'll just return the order info
        $expiresAt = $transaction->created_at->addSeconds(60);

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $transaction->id,
                'total_cents' => $totalCents,
                'total_formatted' => 'Rp ' . number_format($totalCents / 100, 0, ',', '.'),
                'status' => 'awaiting_rfid',
                'expires_at' => $expiresAt->toISOString(),
            ]
        ], 201);
    }

    public function status(Request $request, string $id)
    {
        $cashier = $request->user();

        $transaction = Transaction::where('id', $id)
            ->where('console_id', $request->console->id)
            ->where('cashier_id', $cashier->id)
            ->with('transactionItems.menuItem')
            ->firstOrFail();

        $data = [
            'order_id' => $transaction->id,
            'total_cents' => $transaction->total_cents,
            'total_formatted' => 'Rp ' . number_format($transaction->total_cents / 100, 0, ',', '.'),
            'status' => $transaction->status,
            'items' => $transaction->transactionItems->map(fn($i) => [
                'name' => $i->menuItem?->name,
                'quantity' => $i->quantity,
                'unit_price_cents' => $i->unit_price_cents,
                'subtotal_cents' => $i->subtotalCents(),
            ]),
        ];

        if ($transaction->status === 'completed') {
            $data['receipt_no'] = $transaction->receipt_no;
            $data['buyer_name'] = $transaction->buyer?->name;
            $data['new_balance_cents'] = $transaction->buyer?->balance_cents;
            $data['completed_at'] = $transaction->synced_at?->toISOString();
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
            ->where('console_id', $request->console->id)
            ->where('cashier_id', $cashier->id)
            ->where('status', 'awaiting_rfid')
            ->firstOrFail();

        $transaction->update(['status' => 'voided']);

        Log::channel('audit')->info('Order cancelled by cashier', [
            'transaction_id' => $transaction->id,
            'cashier_id' => $cashier->id,
        ]);

        // Broadcast order_cancelled to console (Ticket 09)
        
        return response()->json([
            'success' => true,
            'message' => 'Order dibatalkan.',
        ]);
    }
}