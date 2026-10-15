<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PasswordReset;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Admin membuat kode reset untuk user lain.
 * Kode polos hanya ditampilkan sekali, di halaman ini.
 */
class ForgotPasswordController extends Controller
{
    /** Maksimal kode yang boleh dibuat untuk satu user per jam. */
    private const MAX_PER_HOUR = 3;

    public function create(Request $request, ?User $user = null)
    {
        $target = $user;

        return view('admin.password-resets.create', [
            'user' => $target,
            'users' => $target ? collect() : User::where('role', '!=', 'admin')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role']),
        ]);
    }

    public function store(Request $request, AuditLogger $audit)
    {
        $validated = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
        ]);

        $user = User::findOrFail($validated['user_id']);

        $key = "password-reset:generate:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_HOUR)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withErrors([
                'user_id' => "Terlalu banyak kode dibuat. Coba lagi dalam {$seconds} detik.",
            ]);
        }

        ['code' => $code, 'reset' => $reset] = PasswordReset::generateForUser($user);

        RateLimiter::hit($key, 3600);

        $audit->log(AuditLogger::ACT_PASSWORD_RESET_GENERATED, [
            'expires_at' => $reset->expires_at->toIso8601String(),
        ], null, $user->id);

        return redirect()
            ->route('admin.password-resets.create')
            ->with('success', "Kode reset untuk {$user->name} berhasil dibuat.")
            // Kode polos hanya ada di flash ini, tidak disimpan di halaman.
            ->with('reset_code', [
                'user_name' => $user->name,
                'code' => $code,
                'expires_at' => $reset->expires_at->toIso8601String(),
            ]);
    }

    public function destroy(Request $request, User $user, AuditLogger $audit)
    {
        PasswordReset::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $audit->log('admin.password_reset_cancelled', [], null, $user->id);

        return back()->with('success', "Semua kode reset aktif untuk {$user->name} dicabut.");
    }
}