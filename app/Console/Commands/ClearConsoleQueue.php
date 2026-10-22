<?php

namespace App\Console\Commands;

use App\Models\Console;
use App\Models\ConsoleCommand;
use App\Services\ConsoleCommandService;
use Illuminate\Console\Command;

/**
 * Ticket 10: minta perangkat mengosongkan antrean tap offline dari CLI.
 *
 * Versi tanpa UI dari tombol "Kosongkan Antrean" di halaman admin console,
 * berguna saat admin hanya punya akses SSH.
 */
class ClearConsoleQueue extends Command
{
    protected $signature = 'console:queue-clear
                            {device_id : Device ID perangkat, mis. ESP-AABBCCDDEEFF}';

    protected $description = 'Antrekan perintah queue.clear untuk perangkat kasir';

    public function handle(ConsoleCommandService $commands): int
    {
        $deviceId = strtoupper(trim((string) $this->argument('device_id')));

        $console = Console::query()->where('device_id', $deviceId)->first();

        if (! $console) {
            $this->error("Console {$deviceId} tidak ditemukan.");

            return self::FAILURE;
        }

        $command = $commands->dispatch($console, ConsoleCommand::COMMAND_QUEUE_CLEAR);

        $this->info("Perintah queue.clear dibuat untuk {$console->device_id} (id: {$command->id}).");
        $this->line('Perangkat akan menjalankannya pada heartbeat/sync berikutnya.');

        return self::SUCCESS;
    }
}
