<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

/**
 * Kode reset password 6 digit.
 *
 * Kode polos hanya pernah dikembalikan satu kali pada saat pembuatan; yang
 * disimpan di database adalah hash-nya. Kalau database bocor, kode yang
 * tersimpan tidak bisa langsung dipakai.
 */
class PasswordReset extends Model
{
    use HasFactory, HasUuids;

    public const CODE_LENGTH = 6;

    /** Berapa lama kode masih berlaku. */
    public const EXPIRES_IN_MINUTES = 60;

    protected $table = 'password_resets';

    protected $keyType = 'uuid';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'code_hash',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    /**
     * Buat kode baru untuk user dan cabut kode lama yang masih berlaku,
     * supaya hanya satu kode aktif per user pada satu waktu.
     *
     * @return array{reset: PasswordReset, code: string} kode polos, hanya tampil sekali
     */
    public static function generateForUser(User $user, ?int $expiresInMinutes = null): array
    {
        // Kode lama ikut dibatalkan supaya tidak ada dua kode aktif bersamaan.
        static::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $code = (string) random_int(100000, 999999);

        $reset = static::create([
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($expiresInMinutes ?? self::EXPIRES_IN_MINUTES),
        ]);

        return ['reset' => $reset, 'code' => $code];
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /** Kode sudah dipakai atau kedaluwarsa? */
    public function isSpent(): bool
    {
        return $this->used_at !== null || $this->expires_at->isPast();
    }

    public function verifyCode(string $code): bool
    {
        return $this->isUsable() && Hash::check($code, $this->code_hash);
    }

    /**
     * Cari kode yang masih berlaku milik user, lalu tandai sudah dipakai.
     *
     * @return bool true bila kode valid dan baru saja dikonsumsi
     */
    public static function consumeFor(User $user, string $code): bool
    {
        $reset = static::query()
            ->where('user_id', $user->id)
            ->valid()
            ->latest('created_at')
            ->get()
            ->first(fn ($r) => Hash::check($code, $r->code_hash));

        if (! $reset) {
            return false;
        }

        $reset->update(['used_at' => now()]);

        return true;
    }

    /** Berapa banyak kode aktif milik user (untuk rate limit). */
    public static function activeCountFor(User $user): int
    {
        return static::query()->where('user_id', $user->id)->valid()->count();
    }
}