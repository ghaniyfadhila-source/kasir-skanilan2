<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class DefaultSettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Setting::definitions() as $key => $def) {
            $value = match ($def['type']) {
                'bool' => $def['default'] ? '1' : '0',
                default => (string) $def['default'],
            };

            // firstOrCreate: jangan menimpa nilai yang sudah diubah admin.
            Setting::firstOrCreate(
                ['key' => $key],
                ['value' => $value, 'type' => $def['type']]
            );
        }

        Setting::flushCache();
    }
}