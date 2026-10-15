<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'audit_logs';

    protected $fillable = [
        'actor_id',
        'console_id',
        'user_id',
        'action',
        'metadata',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /** Admin yang melakukan aksi. */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** User yang KENA aksi (siswa top-up, pembeli tap, dsb). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function console(): BelongsTo
    {
        return $this->belongsTo(Console::class);
    }

    public function scopeBetween(Builder $query, $start, $end): Builder
    {
        return $query->whereBetween('created_at', [
            \Illuminate\Support\Carbon::parse($start)->startOfDay(),
            \Illuminate\Support\Carbon::parse($end)->endOfDay(),
        ]);
    }
}