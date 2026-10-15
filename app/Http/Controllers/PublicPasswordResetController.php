<?php

namespace App\Http\Controllers;

use App\Models\PasswordReset;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Form reset password publik (tanpa login).
 *
 * Alurnya: user/admin memberi kode 6 digit kepada pemilik akun, lalu pemilik
 * akun membukanya di halaman ini dan mengetik kode tersebut.
 * Tidak ada dependensi email sama sekali.
 */
class PublicPasswordResetController extends Controller
{
    /** Maksimal percobaan tebak kode per IP per jam. */
    private const MAX_ATTEMPTS_PER_HOUR = 10;

    public function show()
    {
        return view('password-reset.request');
    }

    public function verify(Request $request, AuditLogger $audit)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'size:'.PasswordReset::CODE_LENGTH, 'regex:/^\d+$/'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $key = 'password-reset:verify:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS_PER_HOUR)) {
            throw ValidationException::withMessages([
                'code' => 'Terlalu banyak percobaan. Coba lagi dalam '
                    .RateLimiter::availableIn($key).' detik.',
            ]);
        }

        $user = User::where('email', $validated['email'])->first();

        // Pesan sengaja sama untuk email tidak dikenal maupun kode salah,
        // supaya tidak bisa dipakai menebak email mana yang terdaftar.
        $invalid = ValidationException::withMessages([
            'code' => 'Kode reset tidak valid atau sudah kadaluarsa.',
        ]);

        if (! $user) {
            RateLimiter::hit($key, 3600);

            throw $invalid;
        }

        if (! PasswordReset::consumeFor($user, $validated['code'])) {
            RateLimiter::hit($key, 3600);

            throw $invalid;
        }

        $user->password = $validated['password']; // cast `hashed` di model
        $user->save();

        // Token Sanctum lama dicabut supaya sesi di perangkat lain ikut logout.
        $user->tokens()->delete();

        RateLimiter::clear($key);

        $audit->log(AuditLogger::ACT_PASSWORD_RESET_USED, [], null, $user->id);

        return redirect()
            ->route('admin.login')
            ->with('success', 'Password berhasil diubah. Silakan masuk dengan password baru.');
    }
}