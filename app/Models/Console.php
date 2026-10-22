<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

class Console extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected $keyType = 'uuid';
    public $incrementing = false;

    public function auditPrefix(): string
    {
        return 'console';
    }

    /**
     * Field heartbeat dikecualikan: berubah tiap menit dan akan memenuhi
     * tabel audit dengan noise.
     */
    public function auditWhitelistedAttributes(): array
    {
        return ['device_id', 'name', 'location', 'status', 'is_active'];
    }

    protected $fillable = [
        'device_id',
        'name',
        'location',
        'status',
        'last_heartbeat_at',
        'firmware_version',
        'ip_address',
        'wifi_rssi',
        'queue_pending',
        'queue_failed',
        'queue_reported_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'last_heartbeat_at' => 'datetime',
            'queue_pending' => 'integer',
            'queue_failed' => 'integer',
            'queue_reported_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function credentials(): HasOne
    {
        return $this->hasOne(ConsoleCredential::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(ConsoleCommand::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function cashier(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, 'device_id', 'device_id')
            ->where('role', 'cashier');
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public static function generateDeviceId(): string
    {
        // In production, this would use the ESP8266 MAC address
        // Format: ESP-XXXXXXXXXXXX (12 hex chars from MAC)
        $hexChars = '0123456789ABCDEF';
        $deviceId = 'ESP-';
        for ($i = 0; $i < 12; $i++) {
            $deviceId .= $hexChars[random_int(0, 15)];
        }
        return $deviceId;
    }

    public static function validateDeviceId(string $deviceId): bool
    {
        return preg_match('/^ESP-[0-9A-F]{12}$/', $deviceId) === 1;
    }

    public function getStatusBadgeClass(): string
    {
        return match ($this->status) {
            'online' => 'bg-green-100 text-green-800',
            'offline' => 'bg-gray-100 text-gray-800',
            'maintenance' => 'bg-yellow-100 text-yellow-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'online' => 'Online',
            'offline' => 'Offline',
            'maintenance' => 'Maintenance',
            default => 'Unknown',
        };
    }
}