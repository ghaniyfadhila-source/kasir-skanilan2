<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Console;
use App\Models\ConsoleCredential;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $console = Console::firstOrCreate(
            ['device_id' => 'ESP-AABBCCDDEEFF'],
            ['name' => 'Kasir Kantin Utama', 'location' => 'Lantai 1, Depat Pintu Masuk', 'status' => 'online', 'is_active' => true]
        );

        // Kredensial perangkat: password untuk login Cashier Web UI,
        // api_key untuk endpoint hardware (header X-API-Key).
        ConsoleCredential::firstOrCreate(
            ['console_id' => $console->id],
            [
                'password_hash' => Hash::make('kasir12345'),
                'api_key' => ConsoleCredential::generateApiKey(),
            ]
        );

        $makanan = Category::firstOrCreate(['name' => 'Makanan'], ['sort_order' => 1]);
        $minuman = Category::firstOrCreate(['name' => 'Minuman'], ['sort_order' => 2]);

        // Harga ditulis dalam SEN, bukan rupiah: Rp 15.000 = 1500000.
        MenuItem::firstOrCreate(
            ['name' => 'Nasi Goreng'],
            ['category_id' => $makanan->id, 'price_cents' => 1500000, 'stock' => -1, 'is_active' => true]
        );
        MenuItem::firstOrCreate(
            ['name' => 'Ayam Bakar'],
            ['category_id' => $makanan->id, 'price_cents' => 1800000, 'stock' => 20, 'is_active' => true]
        );
        MenuItem::firstOrCreate(
            ['name' => 'Es Teh Manis'],
            ['category_id' => $minuman->id, 'price_cents' => 500000, 'stock' => -1, 'is_active' => true]
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