<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

class ConsoleCredential extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'uuid';
    public $incrementing = false;

    protected $fillable = [
        'console_id',
        'password_hash',
        'api_key',
        'rotated_at',
    ];

    protected function casts(): array
    {
        return [
            'rotated_at' => 'datetime',
        ];
    }

    public function console(): BelongsTo
    {
        return $this->belongsTo(Console::class);
    }

    public function verifyPassword(string $password): bool
    {
        return \Illuminate\Support\Facades\Hash::check($password, $this->password_hash);
    }

    public static function generateApiKey(): string
    {
        return Str::lower(bin2hex(random_bytes(32)));
    }

    public static function generatePassword(): string
    {
        return Str::random(16);
    }

    public function rotateCredentials(): void
    {
        $this->update([
            'password_hash' => \Illuminate\Support\Facades\Hash::make(self::generatePassword()),
            'api_key' => self::generateApiKey(),
            'rotated_at' => now(),
        ]);
    }
}