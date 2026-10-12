<?php

namespace Database\Factories;

use App\Models\Console;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ConsoleFactory extends Factory
{
    protected $model = Console::class;

    public function definition(): array
    {
        return [
            'device_id' => 'ESP-' . Str::upper(Str::random(12)),
            'name' => fake()->company() . ' Kasir',
            'location' => fake()->address(),
            'status' => fake()->randomElement(['online', 'offline', 'maintenance']),
            'firmware_version' => fake()->optional()->regexify('v\d+\.\d+\.\d+'),
            'last_heartbeat_at' => fake()->optional()->dateTimeBetween('-1 hour', 'now'),
            'ip_address' => fake()->optional()->ipv4(),
            'wifi_rssi' => fake()->optional()->numberBetween(-90, -30),
            'is_active' => true,
        ];
    }
}