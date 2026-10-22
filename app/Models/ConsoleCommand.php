<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perintah admin yang menunggu diambil perangkat kasir (Ticket 10).
 *
 * Alur: admin menekan tombol -> baris dibuat -> perangkat mengambilnya pada
 * heartbeat/sync -> perangkat mengirim ack -> baris ditandai selesai.
 */
class ConsoleCommand extends Model
{
    use HasFactory, HasUuids;

    /** Kosongkan antrean tap offline milik perangkat. */
    public const COMMAND_QUEUE_CLEAR = 'queue.clear';

    /** Umur maksimum perintah: perintah basi tidak dijalankan setelah berhari-hari. */
    public const TTL_MINUTES = 10;

    public const COMMANDS = [
        self::COMMAND_QUEUE_CLEAR,
    ];

    protected $keyType = 'uuid';

    public $incrementing = false;

    protected $fillable = [
        'console_id',
        'command',
        'payload',
        'delivered_at',
        'acknowledged_at',
        'result',
        'result_message',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function console(): BelongsTo
    {
        return $this->belongsTo(Console::class);
    }

    /**
     * Perintah yang belum di-ack dan belum kedaluwarsa.
     *
     * `delivered_at` sengaja tidak dipakai sebagai filter: perangkat bisa saja
     * kehilangan respons HTTP setelah server mengirim perintah. Perintah baru
     * berhenti dikirim setelah ack diterima.
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query
            ->whereNull('acknowledged_at')
            ->where('created_at', '>=', now()->subMinutes(self::TTL_MINUTES));
    }

    /**
     * Bentuk yang dikirim ke perangkat. Sengaja minimal: firmware hanya butuh
     * id untuk ack, nama perintah, dan payload opsional.
     *
     * @return array{id: string, command: string, payload: array<string, mixed>|null}
     */
    public function toDevicePayload(): array
    {
        return [
            'id' => $this->id,
            'command' => $this->command,
            'payload' => $this->payload,
        ];
    }
}
