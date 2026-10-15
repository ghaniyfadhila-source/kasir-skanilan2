<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Pengaturan aplikasi berbentuk key-value.
 *
 * Nilai disimpan sebagai teks; `type` menentukan cara membacanya
 * (string|int|bool|json). Di-cache supaya tidak query tiap pemakaian.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['key', 'value', 'type'];

    protected static function booted(): void
    {
        static::saved(fn (Setting $setting) => Cache::forget('settings.all'));
        static::deleted(fn (Setting $setting) => Cache::forget('settings.all'));
    }

    /**
     * Definisi semua setting, diambil dari config/kasir.php.
     *
     * @return array<string, array{label: string, type: string, default: mixed, rules?: string}>
     */
    public static function definitions(): array
    {
        return config('kasir.settings', []);
    }

    /**
     * Ambil satu nilai sesuai tipenya.
     *
     * Kalau key belum pernah disimpan, dipakai nilai bawaan dari
     * config/kasir.php. Parameter $default hanya untuk kasus di luar daftar
     * setting (mis. key eksperimental).
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $all = static::allSettings();

        if (array_key_exists($key, $all)) {
            return match ($all[$key]['type']) {
                'int' => (int) $all[$key]['value'],
                'bool' => filter_var($all[$key]['value'], FILTER_VALIDATE_BOOLEAN),
                'json' => json_decode($all[$key]['value'] ?? 'null', true),
                default => $all[$key]['value'],
            };
        }

        $definition = static::definitions()[$key] ?? null;

        return $definition['default'] ?? $default;
    }

    public static function put(string $key, mixed $value, string $type = 'string'): void
    {
        $stored = match ($type) {
            'bool' => $value ? '1' : '0',
            'json' => json_encode($value),
            default => (string) $value,
        };

        static::updateOrCreate(['key' => $key], ['value' => $stored, 'type' => $type]);
    }

    /** @return array<string, array{value: ?string, type: string}> */
    public static function allSettings(): array
    {
        return Cache::rememberForever('settings.all', function () {
            return static::query()
                ->get()
                ->mapWithKeys(fn (Setting $s) => [$s->key => ['value' => $s->value, 'type' => $s->type]])
                ->all();
        });
    }

    public static function flushCache(): void
    {
        Cache::forget('settings.all');
    }
}