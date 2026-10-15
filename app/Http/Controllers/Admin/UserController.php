<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Console;
use App\Models\RfidCard;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /** Rupiah → sen (cents). */
    private const RUPIAH_TO_CENTS = 100;

    public function index(Request $request)
    {
        $query = User::with('rfidCards')->latest();

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role') && $request->role !== 'all') {
            $query->where('role', $request->role);
        }

        $users = $query->paginate(15)->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    public function create()
    {
        return view('admin.users.create', [
            'unassignedConsoles' => $this->availableConsoles(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(['student', 'cashier'])],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'device_id' => ['nullable', 'string', 'exists:consoles,device_id'],
            'initial_balance' => ['nullable', 'integer', 'min:0', 'max:50000000'],
        ]);

        $isCashier = $validated['role'] === 'cashier';

        // Role Kasir wajib punya password + console yang masih bebas.
        if ($isCashier) {
            if (! $request->filled('password')) {
                throw ValidationException::withMessages([
                    'password' => 'Password wajib diisi untuk role Kasir.',
                ]);
            }

            $this->guardConsoleIsFree($validated['device_id'] ?? null);
        }

        $plainPassword = $isCashier || $request->filled('password')
            ? $request->input('password')
            : Str::random(12);

        $user = DB::transaction(function () use ($validated, $isCashier, $plainPassword) {
            return User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'role' => $validated['role'],
                // Cast `hashed` di model akan mem-hash otomatis.
                'password' => $plainPassword,
                'device_id' => $isCashier ? $validated['device_id'] : null,
                'balance_cents' => ((int) ($validated['initial_balance'] ?? 0)) * self::RUPIAH_TO_CENTS,
            ]);
        });

        $this->audit('user.created', $user, [
            'role' => $user->role,
            'device_id' => $user->device_id,
        ]);

        $session = ['success' => 'User berhasil dibuat.'];

        if ($isCashier) {
            $session['success'] = 'Cashier berhasil dibuat.';
            $session['credentials'] = [
                'device_id' => $user->device_id,
                'password' => $plainPassword,
            ];
        }

        return redirect()->route('admin.users.edit', ['user' => $user->getRouteKey()])
            ->with($session);
    }

    public function show(User $user)
    {
        $transactions = $user->transactionsAsBuyer()
            ->with('transactionItems.menuItem')
            ->latest()
            ->paginate(20);

        $user->load('rfidCards');

        return view('admin.users.show', compact('user', 'transactions'));
    }

    public function edit(User $user)
    {
        $user->load('rfidCards');

        return view('admin.users.edit', [
            'user' => $user,
            'unassignedConsoles' => $this->availableConsoles($user->device_id),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(['student', 'cashier'])],
            'device_id' => ['nullable', 'string', 'exists:consoles,device_id'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $isCashier = $validated['role'] === 'cashier';
        $deviceId = $validated['device_id'] ?? null;

        if ($isCashier) {
            if (! $deviceId) {
                throw ValidationException::withMessages([
                    'device_id' => 'Device ID console wajib dipilih untuk role Kasir.',
                ]);
            }

            $this->guardConsoleIsFree($deviceId, $user);
        }

        $plainPassword = $request->filled('password') ? $request->input('password') : null;

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            // Role turun ke Siswa → lepaskan console.
            'device_id' => $isCashier ? $deviceId : null,
        ]);

        if ($plainPassword !== null) {
            $user->password = $plainPassword;
        }

        $user->save();

        $this->audit('user.updated', $user, [
            'role' => $user->role,
            'device_id' => $user->device_id,
            'password_changed' => $plainPassword !== null,
        ]);

        $session = ['success' => 'User berhasil diperbarui.'];

        if ($plainPassword !== null) {
            $session['credentials'] = [
                'device_id' => $user->device_id,
                'password' => $plainPassword,
            ];
        }

        return redirect()->route('admin.users.edit', ['user' => $user->getRouteKey()])
            ->with($session);
    }

    public function destroy(User $user)
    {
        if ($user->isAdmin()) {
            return back()->withErrors(['user' => 'User dengan role Admin tidak dapat dihapus.']);
        }

        if ($user->transactionsAsBuyer()->exists() || $user->transactionsAsCashier()->exists()) {
            return back()->withErrors(['user' => 'User tidak dapat dihapus karena memiliki riwayat transaksi.']);
        }

        $snapshot = ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];

        // Kartu RFID ikut terhapus lewat cascade di level database.
        $user->delete();

        $this->audit('user.deleted', null, $snapshot);

        return redirect()->route('admin.users.index')
            ->with('success', 'User berhasil dihapus.');
    }

    // ------------------------------------------------------------------
    // RFID
    // ------------------------------------------------------------------

    public function storeRfid(Request $request, User $user)
    {
        $validated = $request->validate([
            'rfid_uid' => ['required', 'string', 'regex:/^[0-9A-Fa-f]{8}$/', 'unique:rfid_cards,rfid_uid'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $card = $user->assignRfid($validated['rfid_uid'], $validated['label'] ?? null);

        $this->audit('rfid.assigned', $user, [
            'rfid_card_id' => $card->id,
            'rfid_uid' => $card->rfid_uid,
            'label' => $card->label,
        ]);

        return back()->with('success', 'Kartu RFID berhasil ditambahkan.');
    }

    public function destroyRfid(User $user, RfidCard $rfidCard)
    {
        abort_unless($rfidCard->user_id === $user->id, 404);

        if ($rfidCard->revoked_at !== null) {
            return back()->with('success', 'Kartu RFID sudah dicabut sebelumnya.');
        }

        $user->revokeRfid($rfidCard->id);

        $this->audit('rfid.revoked', $user, [
            'rfid_card_id' => $rfidCard->id,
            'rfid_uid' => $rfidCard->rfid_uid,
        ]);

        return back()->with('success', 'Kartu RFID berhasil dicabut.');
    }

    // ------------------------------------------------------------------
    // Saldo
    // ------------------------------------------------------------------

    public function topUp(Request $request, User $user)
    {
        if ($user->isCashier() || $user->isAdmin()) {
            return back()->withErrors(['user' => 'Top-up saldo hanya untuk role Siswa.']);
        }

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1000', 'max:50000000'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $newBalance = $user->topUp(
            ((int) $validated['amount']) * self::RUPIAH_TO_CENTS,
            $validated['description'] ?? null,
        );

        return back()
            ->with('success', 'Saldo berhasil ditambahkan.')
            ->with('new_balance_cents', $newBalance);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Console aktif yang belum dipakai kasir lain.
     * $currentDeviceId dipakai agar console milik user ini tetap bisa dipilih ulang.
     */
    private function availableConsoles(?string $currentDeviceId = null)
    {
        return Console::query()
            ->where('is_active', true)
            ->where(function ($q) use ($currentDeviceId) {
                $q->whereDoesntHave('cashier')
                    ->when($currentDeviceId, fn ($sub) => $sub->orWhere('device_id', $currentDeviceId));
            })
            ->orderBy('name')
            ->get(['id', 'device_id', 'name', 'location']);
    }

    /** Pastikan console belum diikat ke kasir lain. */
    private function guardConsoleIsFree(?string $deviceId, ?User $except = null): void
    {
        if (! $deviceId) {
            throw ValidationException::withMessages([
                'device_id' => 'Device ID console wajib dipilih.',
            ]);
        }

        $console = Console::where('device_id', $deviceId)->firstOrFail();

        if (! $console->is_active) {
            throw ValidationException::withMessages([
                'device_id' => 'Console yang dipilih tidak aktif.',
            ]);
        }

        $owner = $console->cashier()->first();

        if ($owner && (! $except || $owner->id !== $except->id)) {
            throw ValidationException::withMessages([
                'device_id' => "Console {$console->device_id} sudah dipakai kasir lain ({$owner->name}).",
            ]);
        }
    }

    /** Catat jejak audit aplikasi (channel terpisah dari audit_logs tabel). */
    private function audit(string $action, ?User $user, array $context = []): void
    {
        Log::channel('audit')->info($action, array_merge($context, [
            'subject_user_id' => $user?->id,
            'admin_id' => Auth::id(),
        ]));
    }
}