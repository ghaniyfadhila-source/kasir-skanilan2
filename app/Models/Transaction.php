<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Transaction extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'uuid';
    public $incrementing = false;

    protected $fillable = [
        'console_id',
        'user_id',
        'cashier_id',
        'items',
        'total_cents',
        'status',
        'idempotency_key',
        'error_code',
        'error_message',
        'completed_at',
        'voided_at',
        'voided_by',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'total_cents' => 'integer',
            'completed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function console(): BelongsTo
    {
        return $this->belongsTo(Console::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function formattedTotal(): Attribute
    {
        return Attribute::make(
            get: fn() => 'Rp ' . number_format($this->total_cents / 100, 0, ',', '.'),
        );
    }
}