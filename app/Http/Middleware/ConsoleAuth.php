<?php

namespace App\Http\Middleware;

use App\Models\Console;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi perangkat kasir (ESP8266).
 *
 * Header:
 *   X-Device-ID : device_id console, contoh ESP-AABBCCDDEEFF
 *   X-API-Key   : api_key dari console_credentials
 *
 * Konsol yang berhasil diautentikasi ditempel ke request sebagai `$request->console`
 * agar controller tidak perlu mencari ulang.
 */
class ConsoleAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $deviceId = $request->header('X-Device-ID');
        $apiKey = $request->header('X-API-Key');

        if (! $deviceId || ! $apiKey) {
            return $this->deny('Header X-Device-ID dan X-API-Key wajib diisi.');
        }

        $console = Console::where('device_id', $deviceId)->first();

        if (! $console || ! $console->is_active) {
            // Jangan bocorkan apakah device_id terdaftar atau tidak.
            return $this->deny('Perangkat tidak dikenali atau tidak aktif.', 401);
        }

        $credential = $console->credentials;

        // hash_equals mencegah timing attack pada perbandingan API key.
        if (! $credential || ! hash_equals((string) $credential->api_key, $apiKey)) {
            return $this->deny('API key tidak valid.', 401);
        }

        $request->attributes->set('console', $console);

        return $next($request);
    }

    private function deny(string $message, int $status = 401): Response
    {
        return response()->json([
            'success' => false,
            'error_code' => 'UNAUTHORIZED',
            'message' => $message,
        ], $status);
    }
}