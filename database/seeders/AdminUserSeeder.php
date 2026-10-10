<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@kasir.local'],
            [
                'name' => 'Admin Kasir',
                'password' => Hash::make('password123'),
                'role' => 'admin',
                'balance_cents' => 0,
            ]
        );
    }
}