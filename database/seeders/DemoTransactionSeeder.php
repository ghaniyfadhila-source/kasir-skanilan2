<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Console;
use App\Models\ConsoleCredential;
use App\Models\MenuItem;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Data transaksi riwayat supaya halaman Laporan bisa langsung dicoba
 * tanpa harus menunggu pembayaran sungguhan.
 */
class DemoTransactionSeeder extends Seeder
{
    public function run(): void
    {
        $console = Console::first();
        $category = Category::firstOrCreate(['name' => 'Makanan'], ['sort_order' => 1]);

        $items = collect(['Nasi Goreng' => 15000, 'Ayam Bakar' => 18000, 'Es Teh Manis' => 5000])
            ->map(fn ($price, $name) => MenuItem::firstOrCreate(
                ['name' => $name],
                ['category_id' => $category->id, 'price_cents' => $price, 'stock' => -1, 'is_active' => true]
            ));

        $cashier = User::firstOrCreate(
            ['email' => 'rina@kantin.sch.id'],
            ['name' => 'Rina Kasir', 'password' => 'kasir12345', 'role' => 'cashier', 'device_id' => $console->device_id]
        );

        $buyers = collect(range(1, 5))->map(fn ($i) => User::firstOrCreate(
            ['email' => "murid{$i}@kantin.sch.id"],
            ['name' => "Siswa Contoh {$i}", 'password' => 'password123', 'role' => 'student', 'balance_cents' => 5000000]
        ));

        // 14 hari terakhir, transaksi acak supaya grafik terlihat berisi.
        $sequence = 1;

        for ($daysAgo = 13; $daysAgo >= 0; $daysAgo--) {
            $count = random_int(2, 6);

            for ($i = 0; $i < $count; $i++) {
                $when = Carbon::now()->subDays($daysAgo)
                    ->setTime(random_int(8, 14), random_int(0, 59), random_int(0, 59));

                $lines = $items->random(random_int(1, 2))->map(fn ($item) => [
                    'item' => $item,
                    'qty' => random_int(1, 3),
                ]);

                $total = $lines->sum(fn ($l) => $l['item']->price_cents * $l['qty']);

                $buyer = $buyers->random();

                $tx = Transaction::create([
                    'console_id' => $console->id,
                    'cashier_id' => $cashier->id,
                    'user_id' => $buyer->id,
                    'total_cents' => $total,
                    'status' => Transaction::STATUS_COMPLETED,
                    'completed_at' => $when,
                    'synced_at' => $when,
                    'receipt_no' => sprintf('TXN-%s-%04d', $when->format('Ymd'), $sequence),
                    'idempotency_key' => 'demo-'.Str::uuid(),
                ]);

                foreach ($lines as $line) {
                    TransactionItem::create([
                        'transaction_id' => $tx->id,
                        'menu_item_id' => $line['item']->id,
                        'quantity' => $line['qty'],
                        'unit_price_cents' => $line['item']->price_cents,
                    ]);
                }

                $buyer->decrement('balance_cents', $total);
                $sequence++;
            }
        }

        $this->command?->info("  {$sequence} transaksi demo dibuat.");
    }
}