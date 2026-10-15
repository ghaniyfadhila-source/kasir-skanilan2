<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Console;
use App\Models\MenuItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Console $console;
    private User $cashier;
    private MenuItem $itemA;
    private MenuItem $itemB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->console = Console::factory()->create(['is_active' => true]);
        $this->cashier = User::factory()->create([
            'role' => 'cashier',
            'device_id' => $this->console->device_id,
        ]);

        $category = Category::factory()->create();
        $this->itemA = MenuItem::factory()->create([
            'category_id' => $category->id, 'name' => 'Nasi Goreng',
            'price_cents' => 15000, 'stock' => -1, 'is_active' => true,
        ]);
        $this->itemB = MenuItem::factory()->create([
            'category_id' => $category->id, 'name' => 'Es Teh',
            'price_cents' => 5000, 'stock' => -1, 'is_active' => true,
        ]);
    }

    private function completed(int $totalCents, string $when, ?User $buyer = null, array $items = []): Transaction
    {
        $buyer ??= User::factory()->create(['role' => 'student', 'balance_cents' => 1000000]);

        $tx = Transaction::create([
            'console_id' => $this->console->id,
            'cashier_id' => $this->cashier->id,
            'user_id' => $buyer->id,
            'total_cents' => $totalCents,
            'status' => Transaction::STATUS_COMPLETED,
            'completed_at' => Carbon::parse($when),
            'synced_at' => Carbon::parse($when),
            'receipt_no' => 'TXN-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'idempotency_key' => 'k-'.uniqid(),
        ]);

        foreach ($items as [$menuItem, $qty]) {
            $tx->transactionItems()->create([
                'menu_item_id' => $menuItem->id,
                'quantity' => $qty,
                'unit_price_cents' => $menuItem->price_cents,
            ]);
        }

        return $tx;
    }

    // ------------------------------------------------------------------
    // Akses
    // ------------------------------------------------------------------

    public function test_admin_can_access_reports_index(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Laporan Penjualan');
    }

    public function test_guest_cannot_access_reports(): void
    {
        $this->get(route('admin.reports.index'))->assertRedirect(route('login'));
    }

    public function test_cashier_cannot_access_reports(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('admin.reports.index'))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Ringkasan
    // ------------------------------------------------------------------

    public function test_summary_aggregates_only_completed_transactions(): void
    {
        $buyer = User::factory()->create(['role' => 'student', 'balance_cents' => 1000000]);

        $this->completed(20000, '2026-10-10 09:00', $buyer);
        $this->completed(30000, '2026-10-10 10:00', $buyer);
        $this->completed(10000, '2026-10-11 11:00', $buyer);

        // Tidak boleh dihitung:
        Transaction::create([
            'console_id' => $this->console->id,
            'cashier_id' => $this->cashier->id,
            'total_cents' => 99999,
            'status' => Transaction::STATUS_VOIDED,
            'voided_at' => now(),
            'idempotency_key' => 'voided-1',
        ]);
        Transaction::create([
            'console_id' => $this->console->id,
            'cashier_id' => $this->cashier->id,
            'total_cents' => 88888,
            'status' => Transaction::STATUS_AWAITING_RFID,
            'expires_at' => now()->addMinute(),
            'idempotency_key' => 'pending-1',
        ]);

        $data = $this->actingAs($this->admin)
            ->getJson(route('admin.reports.data', [
                'start' => '2026-10-01',
                'end' => '2026-10-31',
            ]))
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(3, $data['transactions']);
        $this->assertSame(60000, $data['revenue_cents']);
        $this->assertSame(1, $data['unique_buyers']);
        $this->assertSame(20000, $data['avg_cents']);
    }

    public function test_summary_is_zero_when_no_transactions(): void
    {
        $data = $this->actingAs($this->admin)
            ->getJson(route('admin.reports.data', ['start' => '2026-01-01', 'end' => '2026-01-31']))
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(0, $data['transactions']);
        $this->assertSame(0, $data['revenue_cents']);
        $this->assertSame(0, $data['avg_cents'], 'Rata-rata tidak boleh membagi dengan nol');
    }

    // ------------------------------------------------------------------
    // Pendapatan per hari
    // ------------------------------------------------------------------

    public function test_revenue_per_day_fills_gaps_with_zero(): void
    {
        $this->completed(20000, '2026-10-01 09:00');
        $this->completed(30000, '2026-10-03 09:00');

        $rows = $this->actingAs($this->admin)
            ->getJson(route('admin.reports.data', ['start' => '2026-10-01', 'end' => '2026-10-05']))
            ->assertOk()
            ->json('data.revenue_per_day');

        $this->assertCount(5, $rows, 'Semua hari dalam rentang harus ada');
        $this->assertSame('2026-10-01', $rows[0]['date']);
        $this->assertSame(20000, $rows[0]['revenue_cents']);
        $this->assertSame(0, $rows[1]['revenue_cents'], 'Hari tanpa transaksi harus 0');
        $this->assertSame(30000, $rows[2]['revenue_cents']);
        $this->assertSame(0, $rows[3]['revenue_cents']);
        $this->assertSame(0, $rows[4]['revenue_cents']);
    }

    // ------------------------------------------------------------------
    // Menu terlaris
    // ------------------------------------------------------------------

    public function test_top_items_ranked_by_quantity_sold(): void
    {
        $this->completed(15000, '2026-10-10 09:00', null, [[$this->itemA, 1]]);
        $this->completed(60000, '2026-10-10 10:00', null, [[$this->itemA, 4]]);
        $this->completed(15000, '2026-10-10 11:00', null, [[$this->itemB, 3]]);

        $items = $this->actingAs($this->admin)
            ->getJson(route('admin.reports.data', ['start' => '2026-10-01', 'end' => '2026-10-31']))
            ->assertOk()
            ->json('data.top_items');

        $this->assertCount(2, $items);
        $this->assertSame('Nasi Goreng', $items[0]['name']);
        $this->assertSame(5, $items[0]['quantity']);
        $this->assertSame(75000, $items[0]['revenue_cents']);
        $this->assertSame('Es Teh', $items[1]['name']);
        $this->assertSame(3, $items[1]['quantity']);
    }

    public function test_top_items_tie_is_broken_deterministically(): void
    {
        // Jumlah sama: urutan harus stabil, diurutkan berdasarkan nama.
        $this->completed(15000, '2026-10-10 09:00', null, [[$this->itemA, 2]]);
        $this->completed(10000, '2026-10-10 10:00', null, [[$this->itemB, 2]]);

        $first = $this->actingAs($this->admin)
            ->getJson(route('admin.reports.data', ['start' => '2026-10-01', 'end' => '2026-10-31']))
            ->json('data.top_items');

        $this->assertSame(['Es Teh', 'Nasi Goreng'], array_column($first, 'name'));
    }

    // ------------------------------------------------------------------
    // Kasir & console
    // ------------------------------------------------------------------

    public function test_by_cashier_groups_transactions(): void
    {
        $this->completed(20000, '2026-10-10 09:00');
        $this->completed(30000, '2026-10-10 10:00');

        $rows = $this->actingAs($this->admin)
            ->getJson(route('admin.reports.data', ['start' => '2026-10-01', 'end' => '2026-10-31']))
            ->assertOk()
            ->json('data.by_cashier');

        $this->assertCount(1, $rows);
        $this->assertSame($this->cashier->name, $rows[0]['cashier']);
        $this->assertSame(2, $rows[0]['transactions']);
        $this->assertSame(50000, $rows[0]['revenue_cents']);
    }

    public function test_by_console_groups_transactions(): void
    {
        $this->completed(20000, '2026-10-10 09:00');

        $rows = $this->actingAs($this->admin)
            ->getJson(route('admin.reports.data', ['start' => '2026-10-01', 'end' => '2026-10-31']))
            ->assertOk()
            ->json('data.by_console');

        $this->assertCount(1, $rows);
        $this->assertSame($this->console->name, $rows[0]['console']);
    }

    // ------------------------------------------------------------------
    // Rentang tanggal
    // ------------------------------------------------------------------

    public function test_reversed_date_range_is_swapped(): void
    {
        $this->completed(20000, '2026-10-10 09:00');

        $response = $this->actingAs($this->admin)
            ->getJson(route('admin.reports.data', ['start' => '2026-10-31', 'end' => '2026-10-01']))
            ->assertOk();

        $this->assertSame('2026-10-01', $response->json('data.start'));
        $this->assertSame('2026-10-31', $response->json('data.end'));
        $this->assertSame(1, $response->json('data.summary.transactions'));
    }

    public function test_range_is_capped_to_366_days(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson(route('admin.reports.data', ['start' => '2000-01-01', 'end' => '2026-10-10']))
            ->assertOk();

        $start = \Illuminate\Support\Carbon::parse($response->json('data.start'));
        $end = \Illuminate\Support\Carbon::parse($response->json('data.end'));

        $this->assertLessThanOrEqual(366, $start->diffInDays($end));
    }

    public function test_default_range_is_last_seven_days(): void
    {
        $response = $this->actingAs($this->admin)->getJson(route('admin.reports.data'))->assertOk();

        $start = \Illuminate\Support\Carbon::parse($response->json('data.start'));
        $end = \Illuminate\Support\Carbon::parse($response->json('data.end'));

        $this->assertSame(now()->toDateString(), $end->toDateString());
        $this->assertSame(6, (int) $start->diffInDays($end));
    }

    public function test_transactions_outside_range_are_excluded(): void
    {
        $this->completed(20000, '2026-09-30 09:00'); // di luar rentang
        $this->completed(30000, '2026-10-10 09:00'); // di dalam rentang

        $data = $this->actingAs($this->admin)
            ->getJson(route('admin.reports.data', ['start' => '2026-10-01', 'end' => '2026-10-31']))
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(1, $data['transactions']);
        $this->assertSame(30000, $data['revenue_cents']);
    }

    // ------------------------------------------------------------------
    // Halaman
    // ------------------------------------------------------------------

    public function test_index_shows_transaction_rows(): void
    {
        $buyer = User::factory()->create(['name' => 'Pembeli Test', 'role' => 'student', 'balance_cents' => 1000000]);
        // 4.500.000 sen = Rp 45.000
        $this->completed(4500000, now()->toDateString().' 09:00', $buyer, [[$this->itemA, 3]]);

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Pembeli Test')
            ->assertSee('Nasi Goreng')
            ->assertSee('Rp 45.000');
    }

    public function test_index_handles_empty_range(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['start' => '2020-01-01', 'end' => '2020-01-07']))
            ->assertOk()
            ->assertSee('Tidak ada transaksi selesai pada rentang ini.');
    }

    // ------------------------------------------------------------------
    // Export CSV
    // ------------------------------------------------------------------

    public function test_csv_export_has_header_and_rows(): void
    {
        $buyer = User::factory()->create(['name' => 'Budi Santoso', 'role' => 'student', 'balance_cents' => 1000000]);
        // 4.500.000 sen = Rp 45.000
        $this->completed(4500000, '2026-10-10 09:30', $buyer, [[$this->itemA, 2], [$this->itemB, 1]]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.reports.export', ['start' => '2026-10-01', 'end' => '2026-10-31']));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'CSV harus punya BOM UTF-8 untuk Excel');
        $this->assertStringContainsString('No Struk', $content);
        $this->assertStringContainsString('Budi Santoso', $content);
        $this->assertStringContainsString('Nasi Goreng', $content);
        $this->assertStringContainsString('45.000', $content);
    }

    public function test_csv_filename_contains_range(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.reports.export', ['start' => '2026-10-01', 'end' => '2026-10-31']));

        $this->assertStringContainsString('transactions_2026-10-01_2026-10-31.csv', $response->headers->get('content-disposition'));
    }

    public function test_csv_export_excludes_voided_and_pending(): void
    {
        $this->completed(2000000, '2026-10-10 09:00'); // Rp 20.000

        Transaction::create([
            'console_id' => $this->console->id,
            'cashier_id' => $this->cashier->id,
            'total_cents' => 5555500, // Rp 55.555
            'status' => Transaction::STATUS_VOIDED,
            'voided_at' => now(),
            'idempotency_key' => 'v-1',
        ]);

        $content = $this->actingAs($this->admin)
            ->get(route('admin.reports.export', ['start' => '2026-10-01', 'end' => '2026-10-31']))
            ->streamedContent();

        $this->assertStringContainsString('20.000', $content);
        $this->assertStringNotContainsString('55.555', $content);
    }

    public function test_csv_export_with_no_data_returns_only_header(): void
    {
        $content = $this->actingAs($this->admin)
            ->get(route('admin.reports.export', ['start' => '2020-01-01', 'end' => '2020-01-07']))
            ->streamedContent();

        $lines = array_filter(explode("\n", $content));
        $this->assertCount(1, $lines, 'Hanya header yang diekspor');
    }

    // ------------------------------------------------------------------
    // Caching
    // ------------------------------------------------------------------

    public function test_chart_data_is_cached_per_range(): void
    {
        $this->completed(20000, '2026-10-10 09:00');

        $url = route('admin.reports.data', ['start' => '2026-10-01', 'end' => '2026-10-31']);

        $this->actingAs($this->admin)->getJson($url)->assertOk();

        $key = 'reports:2026-10-01:2026-10-31';
        $this->assertTrue(cache()->has($key), 'Data grafik harus di-cache');

        // Transaksi baru dalam 5 menit tidak langsung mengubah hasil cached.
        $this->completed(99999, '2026-10-11 09:00');

        $cached = $this->actingAs($this->admin)->getJson($url)->assertOk()->json('data.summary');
        $this->assertSame(1, $cached['transactions']);

        cache()->forget($key);

        $fresh = $this->actingAs($this->admin)->getJson($url)->assertOk()->json('data.summary');
        $this->assertSame(2, $fresh['transactions'], 'Setelah cache dibersihkan data baru masuk');
    }
}