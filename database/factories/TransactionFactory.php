<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\Console;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'console_id' => Console::factory(),
            'user_id' => User::factory()->state(['role' => 'student']),
            'cashier_id' => User::factory()->state(['role' => 'cashier']),
            'items' => [['menu_item_id' => fake()->uuid(), 'qty' => 1, 'price_cents' => fake()->numberBetween(1000, 50000), 'name' => 'Test Item']],
            'total_cents' => fake()->numberBetween(1000, 50000),
            'status' => fake()->randomElement(['completed', 'voided', 'pending_sync']),
            'idempotency_key' => fake()->uuid(),
            'synced_at' => fake()->optional()->dateTime(),
        ];
    }
}