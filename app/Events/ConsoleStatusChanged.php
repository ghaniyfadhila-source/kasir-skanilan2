<?php

namespace App\Events;

use App\Models\Console;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Status atau kondisi konsol berubah, misalnya heartbeat dari ESP8266.
 *
 * Nama modelnya `Console` bentrok dengan `Illuminate\Console\Command`, jadi
 * event ini yang akan dibaca listener, bukan kelas modelnya secara langsung.
 */
class ConsoleStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Console $console,
        /** @var array<string, mixed> */
        public readonly array $details = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'console_id' => $this->console->id,
            'device_id' => $this->console->device_id,
            'name' => $this->console->name,
            'status' => $this->console->status,
            'last_seen_at' => $this->console->last_seen_at?->toIso8601String(),
            'details' => $this->details,
        ];
    }
}