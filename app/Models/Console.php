<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Console extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'uuid';
    public $incrementing = false;

    protected $fillable = [
        'device_id',
        'name',
        'location',
        'status',
        'last_heartbeat_at',
        'firmware_version',
        'ip_address',
        'wifi_rssi',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'last_heartbeat_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function credentials(): HasOne
    {
        return $this->hasOne(ConsoleCredential::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }
}