<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Transaction extends Model
{
    use HasFactory, HasUuids;

    /** Order dibuat, menunggu pembeli tap kartu RFID. */
    public const STATUS_AWAITING_RFID = 'awaiting_rfid';

    /** Sudah dibayar dan selesai. */
    public const STATUS_COMPLETED = 'completed';

    /** Dibatalkan (kasir cancel atau kedaluwarsa). */
    public const STATUS_VOIDED = 'voided';

    /** Transaksi hardware yang belum tersinkron ke server (Ticket 10). */
    public const STATUS_PENDING_SYNC = 'pending_sync';

    public const STATUSES = [
        self::STATUS_AWAITING_RFID,
        self::STATUS_COMPLETED,
        self::STATUS_VOIDED,
        self::STATUS_PENDING_SYNC,
    ];

    /** Order menunggu tap RFID selama 60 detik. */
    public const RFID_TAP_TIMEOUT_SECONDS = 60;

    protected $keyType = 'uuid';

    public $incrementing = false;

    protected $fillable = [
        'console_id',
        'user_id',
        'cashier_id',
        'items',
        'total_cents',
        'status',
        'expires_at',
        'idempotency_key',
        'receipt_no',
        'error_code',
        'error_message',
        'completed_at',
        'voided_at',
        'voided_by',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'total_cents' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'voided_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function scopeAwaitingRfid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AWAITING_RFID);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->awaitingRfid()->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }

    // ------------------------------------------------------------------
    // State
    // ------------------------------------------------------------------

    public function isAwaitingRfid(): bool
    {
        return $this->status === self::STATUS_AWAITING_RFID;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }

    /** Order sudah lewat batas waktu tap RFID? */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function canBePaidByTap(): bool
    {
        return $this->isAwaitingRfid() && ! $this->isExpired();
    }

    // ------------------------------------------------------------------
    // Receipt
    // ------------------------------------------------------------------

    /**
     * Nomor struk: TXN-{YYYYMMDD}-{urutan hari ini}, contoh TXN-20261016-0007.
     *
     * Urutan dihitung dari jumlah struk yang sudah terbit pada hari tersebut,
     * sehingga nomor selalu naik dan tidak bentrok antar hari. Unik dijamin
     * unique index pada `receipt_no`; pemanggil harus berada dalam transaksi DB.
     */
    public static function generateReceiptNo(?string $date = null): string
    {
        $day = \Illuminate\Support\Carbon::parse($date ?? now())->format('Ymd');

        $sequence = static::query()
            ->where('receipt_no', 'like', "TXN-$day-%")
            ->count() + 1;

        return sprintf('TXN-%s-%04d', $day, $sequence);
    }

    // ------------------------------------------------------------------
    // Presentation
    // ------------------------------------------------------------------

    public function formattedTotal(): Attribute
    {
        return Attribute::make(
            get: fn () => 'Rp '.number_format($this->total_cents / 100, 0, ',', '.'),
        );
    }
}