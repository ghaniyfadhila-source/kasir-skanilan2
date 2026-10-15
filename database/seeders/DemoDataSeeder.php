<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Console;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $console = Console::firstOrCreate(
            ['device_id' => 'ESP-AABBCCDDEEFF'],
            ['name' => 'Kasir Kantin Utama', 'location' => 'Lantai 1, Depat Pintu Masuk', 'status' => 'online', 'is_active' => true]
        );

        $makanan = Category::firstOrCreate(['name' => 'Makanan'], ['sort_order' => 1]);
        $minuman = Category::firstOrCreate(['name' => 'Minuman'], ['sort_order' => 2]);

        MenuItem::firstOrCreate(
            ['name' => 'Nasi Goreng'],
            ['category_id' => $makanan->id, 'price_cents' => 15000, 'is_active' => true]
        );
        MenuItem::firstOrCreate(
            ['name' => 'Ayam Bakar'],
            ['category_id' => $makanan->id, 'price_cents' => 18000, 'is_active' => true]
        );
        MenuItem::firstOrCreate(
            ['name' => 'Es Teh Manis'],
            ['category_id' => $minuman->id, 'price_cents' => 5000, 'is_active' => true]
        );

        $student = User::firstOrCreate(
            ['email' => 'budi@kantin.sch.id'],
            [
                'name' => 'Budi Santoso',
                'password' => 'password123',
                'role' => 'student',
                'balance_cents' => 2500000,
            ]
        );

        if (! $student->rfidCards()->exists()) {
            $student->assignRfid('A1B2C3D4', 'Kartu Utama');
            $student->assignRfid('E5F6A7B8', 'Kartu Cadangan');
        }

        User::firstOrCreate(
            ['email' => 'rina@kantin.sch.id'],
            [
                'name' => 'Rina Kasir',
                'password' => 'kasir12345',
                'role' => 'cashier',
                'device_id' => $console->device_id,
                'balance_cents' => 0,
            ]
        );
    }
}