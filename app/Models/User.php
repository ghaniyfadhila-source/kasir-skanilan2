<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasUuids, HasApiTokens;

    protected $keyType = 'uuid';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'balance_cents',
        'device_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'balance_cents' => 'integer',
        ];
    }

    public function rfidCards(): HasMany
    {
        return $this->hasMany(RfidCard::class);
    }

    public function transactionsAsBuyer(): HasMany
    {
        return $this->hasMany(Transaction::class, 'user_id');
    }

    public function transactionsAsCashier(): HasMany
    {
        return $this->hasMany(Transaction::class, 'cashier_id');
    }

    public function voidedTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'voided_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function passwordResets(): HasMany
    {
        return $this->hasMany(PasswordReset::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isCashier(): bool
    {
        return $this->role === 'cashier';
    }

    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    /** Kartu RFID yang masih aktif (belum dicabut). */
    public function activeRfidCards(): HasMany
    {
        return $this->hasMany(RfidCard::class)
            ->whereNull('revoked_at')
            ->where('is_active', true);
    }

    /**
     * Pasang kartu RFID ke user. UID dinormalisasi ke huruf besar.
     */
    public function assignRfid(string $rfidUid, ?string $label = null): RfidCard
    {
        return $this->rfidCards()->create([
            'rfid_uid' => strtoupper($rfidUid),
            'label' => $label,
            'is_active' => true,
        ]);
    }

    public function revokeRfid(string $rfidCardId): bool
    {
        $card = $this->rfidCards()->find($rfidCardId);
        if (!$card) {
            return false;
        }
        $card->update([
            'is_active' => false,
            'revoked_at' => now(),
        ]);
        return true;
    }

    /**
     * Tambah saldo user. Saldo disimpan dalam sen (cents).
     *
     * Jejak audit TIDAK ditulis di sini karena aktornya (admin yang menekan
     * tombol) hanya diketahui di lapisan controller.
     */
    public function topUp(int $amountCents, ?string $description = null): int
    {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Nominal top-up harus lebih dari 0.');
        }

        DB::transaction(function () use ($amountCents) {
            $this->increment('balance_cents', $amountCents);
        });

        return $this->fresh()->balance_cents;
    }
}