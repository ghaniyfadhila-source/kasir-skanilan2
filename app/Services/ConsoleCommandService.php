<?php

namespace App\Services;

use App\Models\Console;
use App\Models\ConsoleCommand;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Antrean perintah admin -> perangkat kasir (Ticket 10).
 *
 * Perangkat tidak berlangganan WebSocket, jadi perintah dikirim sebagai bagian
 * dari respons heartbeat/sync. Pengiriman bersifat "at least once": perintah
 * terus ikut terkirim sampai perangkat mengirim ack.
 */
class ConsoleCommandService
{
    public function dispatch(Console $console, string $command, ?array $payload = null): ConsoleCommand
    {
        if (! in_array($command, ConsoleCommand::COMMANDS, true)) {
            throw new InvalidArgumentException("Perintah tidak dikenal: {$command}");
        }

        return ConsoleCommand::create([
            'console_id' => $console->id,
            'command' => $command,
            'payload' => $payload,
        ]);
    }

    /**
     * Perintah yang harus ikut dikirim pada respons berikutnya.
     *
     * `delivered_at` hanya dicatat untuk keperluan audit; perintah tetap
     * dikirim ulang sampai di-ack.
     *
     * @return Collection<int, ConsoleCommand>
     */
    public function outstandingFor(Console $console, int $limit = 10): Collection
    {
        $commands = ConsoleCommand::query()
            ->outstanding()
            ->where('console_id', $console->id)
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($commands->isEmpty()) {
            return $commands;
        }

        ConsoleCommand::query()
            ->whereIn('id', $commands->pluck('id'))
            ->whereNull('delivered_at')
            ->update(['delivered_at' => now()]);

        return $commands;
    }

    /**
     * Tandai perintah selesai dikerjakan perangkat.
     *
     * Hanya baris milik console pemanggil yang boleh diubah; id asing diabaikan
     * diam-diam supaya satu perangkat tidak bisa menutup perintah perangkat lain.
     *
     * @param  array<int, array{id?: mixed, status?: mixed, message?: mixed}>  $acks
     * @return int jumlah perintah yang benar-benar ditandai
     */
    public function acknowledge(Console $console, array $acks): int
    {
        $acknowledged = 0;

        foreach ($acks as $ack) {
            $id = is_array($ack) ? ($ack['id'] ?? null) : null;
            $status = is_array($ack) ? ($ack['status'] ?? null) : null;

            if (! is_string($id) || ! is_string($status)) {
                continue;
            }

            if (! in_array($status, ['ok', 'failed'], true)) {
                continue;
            }

            $message = is_string($ack['message'] ?? null)
                ? mb_substr($ack['message'], 0, 255)
                : null;

            $acknowledged += ConsoleCommand::query()
                ->where('id', $id)
                ->where('console_id', $console->id)
                ->whereNull('acknowledged_at')
                ->update([
                    'acknowledged_at' => now(),
                    'result' => $status,
                    'result_message' => $message,
                ]);
        }

        return $acknowledged;
    }
}
