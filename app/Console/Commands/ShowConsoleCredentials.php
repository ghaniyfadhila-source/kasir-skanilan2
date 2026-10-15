<?php

namespace App\Console\Commands;

use App\Models\Console;
use Illuminate\Console\Command;

/**
 * Menampilkan kredensial perangkat kasir supaya bisa di-flash ke ESP8266
 * (Ticket 07) tanpa harus membuka database.
 */
class ShowConsoleCredentials extends Command
{
    protected $signature = 'console:credentials
                            {device_id? : device_id console, contoh ESP-AABBCCDDEEFF}
                            {--rotate : buat api_key baru sebelum ditampilkan}';

    protected $description = 'Tampilkan device_id dan api_key console untuk perangkat keras';

    public function handle(): int
    {
        $query = Console::with('credentials');

        if ($this->argument('device_id')) {
            $query->where('device_id', $this->argument('device_id'));
        }

        $consoles = $query->get();

        if ($consoles->isEmpty()) {
            $this->error('Console tidak ditemukan.');

            return self::FAILURE;
        }

        $rows = $consoles->map(function (Console $console) {
            $credential = $console->credentials;

            if ($this->option('rotate')) {
                if (! $credential) {
                    $this->error("Console {$console->device_id} belum punya kredensial.");

                    return null;
                }

                $credential->update([
                    'api_key' => \App\Models\ConsoleCredential::generateApiKey(),
                    'rotated_at' => now(),
                ]);

                $credential->refresh();
            }

            if (! $credential) {
                return null;
            }

            return [
                $console->device_id,
                $console->name,
                $console->is_active ? 'aktif' : 'nonaktif',
                $credential->api_key,
            ];
        })->filter()->all();

        if ($rows === []) {
            $this->error('Tidak ada kredensial console yang bisa ditampilkan.');

            return self::FAILURE;
        }

        $this->table(['DEVICE ID', 'NAMA', 'STATUS', 'API KEY'], $rows);

        $this->warn('API key bersifat rahasia — jangan di-commit ke repository.');

        return self::SUCCESS;
    }
}