<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Console;
use App\Models\ConsoleCredential;
use App\Models\MenuItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ticket 10: POST /api/console/sync.
 *
 * Kontrak yang diuji di sini adalah kontrak yang dipakai firmware: per item
 * hasil, status yang menentukan apakah perangkat boleh membuang record dari
 * antrean offline-nya, dan server_epoch sebagai sumber waktu.
 */
class ConsoleSyncTest extends TestCase
{
    use RefreshDatabase;

    private Console $console;

    private string $apiKey;

    private User $cashier;

    private User $buyer;

    private MenuItem $menuItem;

    protected function setUp(): void
    {
        parent::setUp();

        // Realtime di-fake: yang diuji di sini pemrosesan batch, bukan Supabase.
        // Tanpa fake, setiap tap melakukan panggilan HTTP sungguhan (dan tes
        // 50 tap butuh lebih dari satu menit).
        config()->set('realtime.channel_secret', 'abcdef0123456789abcdef0123456789');
        config()->set('realtime.url', 'https://contoh.supabase.co');
        config()->set('realtime.client_key', 'sb_publishable_uji');
        config()->set('realtime.server_key', 'sb_secret_uji');

        Http::fake([
            '*/realtime/v1/api/broadcast' => Http::response(['message_ids' => ['m1']], 202),
        ]);

        $this->console = Console::factory()->create(['is_active' => true]);
        $this->apiKey = ConsoleCredential::generateApiKey();

        ConsoleCredential::create([
            'console_id' => $this->console->id,
            'password_hash' => bcrypt('kasir12345'),
            'api_key' => $this->apiKey,
        ]);

        $this->cashier = User::factory()->create([
            'role' => 'cashier',
            'device_id' => $this->console->device_id,
        ]);

        $category = Category::factory()->create();
        $this->menuItem = MenuItem::factory()->create([
            'category_id' => $category->id,
            'price_cents' => 15000,
            'stock' => -1,
            'is_active' => true,
        ]);

        $this->buyer = User::factory()->create([
            'role' => 'student',
            'balance_cents' => 100000,
        ]);
        $this->buyer->assignRfid('A1B2C3D4');
    }

    private function headers(?string $apiKey = null): array
    {
        return [
            'X-Device-ID' => $this->console->device_id,
            'X-API-Key' => $apiKey ?? $this->apiKey,
        ];
    }

    private function makeOrder(int $totalCents = 15000): Transaction
    {
        $order = Transaction::create([
            'console_id' => $this->console->id,
            'cashier_id' => $this->cashier->id,
            'total_cents' => $totalCents,
            'status' => Transaction::STATUS_AWAITING_RFID,
            'expires_at' => now()->addSeconds(Transaction::RFID_TAP_TIMEOUT_SECONDS),
            'idempotency_key' => 'order-'.Str::uuid(),
        ]);

        $order->transactionItems()->create([
            'menu_item_id' => $this->menuItem->id,
            'quantity' => 1,
            'unit_price_cents' => $totalCents,
        ]);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function tap(string $orderId, ?string $key = null, array $overrides = []): array
    {
        return array_merge([
            'idempotency_key' => $key ?? 'tap-'.Str::uuid(),
            'rfid_uid' => 'A1B2C3D4',
            'order_id' => $orderId,
            'created_at' => now()->getTimestamp(),
            'retry_count' => 0,
            'status' => 'pending',
        ], $overrides);
    }

    private function sync(array $taps, array $extra = [])
    {
        return $this->withHeaders($this->headers())
            ->postJson('/api/console/sync', ['taps' => $taps] + $extra);
    }

    /**
     * Kosongkan rekaman request HTTP tanpa menyentuh data test.
     *
     * `Http::clearResolvedInstances()` tidak mengosongkan rekaman, jadi factory
     * harus diganti dengan instance baru.
     */
    private function resetHttpRecorder(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory($this->app['events']));

        Http::fake([
            '*/realtime/v1/api/broadcast' => Http::response(['message_ids' => ['m1']], 202),
        ]);
    }

    // ------------------------------------------------------------------
    // Auth & validasi batch
    // ------------------------------------------------------------------

    public function test_sync_requires_console_auth(): void
    {
        $this->postJson('/api/console/sync', ['taps' => []])->assertUnauthorized();
    }

    public function test_sync_rejects_wrong_api_key(): void
    {
        $this->withHeaders($this->headers('salah'))
            ->postJson('/api/console/sync', ['taps' => []])
            ->assertUnauthorized();
    }

    public function test_taps_key_is_required(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/console/sync', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('taps');
    }

    public function test_more_than_fifty_taps_is_rejected(): void
    {
        $taps = [];

        for ($i = 0; $i < 51; $i++) {
            $taps[] = $this->tap((string) Str::uuid(), "bulk-{$i}");
        }

        $this->sync($taps)
            ->assertStatus(422)
            ->assertJsonValidationErrors('taps');
    }

    public function test_empty_batch_is_accepted(): void
    {
        $this->sync([])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data.results')
            ->assertJsonPath('data.queue_commands', []);
    }

    // ------------------------------------------------------------------
    // Hasil per item
    // ------------------------------------------------------------------

    public function test_batch_processes_every_tap_and_returns_per_item_results(): void
    {
        $first = $this->makeOrder(15000);
        $second = $this->makeOrder(10000);

        $response = $this->sync([
            $this->tap($first->id, 'batch-1'),
            $this->tap($second->id, 'batch-2'),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.results.0.idempotency_key', 'batch-1')
            ->assertJsonPath('data.results.0.status', 'success')
            ->assertJsonPath('data.results.0.transaction_id', $first->id)
            ->assertJsonPath('data.results.0.total_cents', 15000)
            ->assertJsonPath('data.results.1.idempotency_key', 'batch-2')
            ->assertJsonPath('data.results.1.status', 'success');

        $this->assertSame(75000, $this->buyer->fresh()->balance_cents);

        $this->assertDatabaseHas('transactions', [
            'id' => $first->id,
            'status' => Transaction::STATUS_COMPLETED,
            'tap_idempotency_key' => 'batch-1',
        ]);
    }

    public function test_one_malformed_record_does_not_block_the_rest_of_the_batch(): void
    {
        $order = $this->makeOrder();

        $response = $this->sync([
            ['idempotency_key' => 'rusak-1', 'rfid_uid' => 'bukan-hex', 'order_id' => $order->id],
            ['idempotency_key' => 'rusak-2', 'rfid_uid' => 'A1B2C3D4', 'order_id' => 'bukan-uuid'],
            ['idempotency_key' => 'rusak-3', 'rfid_uid' => 'A1B2C3D4'],
            ['rfid_uid' => 'A1B2C3D4', 'order_id' => $order->id],
            $this->tap($order->id, 'bagus-1'),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.results.0.status', 'discard')
            ->assertJsonPath('data.results.1.status', 'discard')
            ->assertJsonPath('data.results.2.status', 'discard')
            ->assertJsonPath('data.results.3.status', 'discard')
            ->assertJsonPath('data.results.4.status', 'success');

        $this->assertSame(85000, $this->buyer->fresh()->balance_cents);
    }

    public function test_same_key_twice_in_one_batch_is_only_charged_once(): void
    {
        $order = $this->makeOrder();

        $response = $this->sync([
            $this->tap($order->id, 'kunci-sama'),
            $this->tap($order->id, 'kunci-sama'),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.results.0.status', 'success')
            ->assertJsonPath('data.results.1.status', 'duplicate');

        $this->assertSame(85000, $this->buyer->fresh()->balance_cents);
    }

    public function test_reusing_a_key_for_another_order_does_not_leak_its_receipt(): void
    {
        $first = $this->makeOrder(10000);
        $second = $this->makeOrder(15000);

        $this->sync([$this->tap($first->id, 'kunci-dipakai-ulang')])
            ->assertJsonPath('data.results.0.status', 'success');

        $response = $this->sync([$this->tap($second->id, 'kunci-dipakai-ulang')]);

        $response->assertOk()
            ->assertJsonPath('data.results.0.status', 'void')
            ->assertJsonMissingPath('data.results.0.receipt_no');

        $this->assertSame(Transaction::STATUS_AWAITING_RFID, $second->refresh()->status);
        $this->assertSame(90000, $this->buyer->fresh()->balance_cents);
    }

    public function test_unknown_card_is_terminal(): void
    {
        $order = $this->makeOrder();

        $this->sync([$this->tap($order->id, 'kartu-asing', ['rfid_uid' => 'DEADBEEF'])])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'card_invalid');

        $this->assertSame(100000, $this->buyer->fresh()->balance_cents);
        $this->assertSame(Transaction::STATUS_AWAITING_RFID, $order->refresh()->status);
    }

    public function test_insufficient_balance_is_terminal(): void
    {
        $this->buyer->update(['balance_cents' => 1000]);
        $order = $this->makeOrder();

        $this->sync([$this->tap($order->id)])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'insufficient_balance');

        $this->assertSame(1000, $this->buyer->fresh()->balance_cents);
    }

    public function test_expired_order_returns_void(): void
    {
        $order = $this->makeOrder();
        $order->update(['expires_at' => now()->subMinute()]);

        $this->sync([$this->tap($order->id)])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'void');

        $this->assertSame(100000, $this->buyer->fresh()->balance_cents);
    }

    public function test_order_from_another_console_is_not_tappable(): void
    {
        $otherConsole = Console::factory()->create(['is_active' => true]);

        $foreignOrder = Transaction::create([
            'console_id' => $otherConsole->id,
            'cashier_id' => $this->cashier->id,
            'total_cents' => 15000,
            'status' => Transaction::STATUS_AWAITING_RFID,
            'expires_at' => now()->addMinute(),
            'idempotency_key' => 'asing-'.Str::uuid(),
        ]);

        $this->sync([$this->tap($foreignOrder->id)])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'void');

        $this->assertSame(Transaction::STATUS_AWAITING_RFID, $foreignOrder->refresh()->status);
        $this->assertSame(100000, $this->buyer->fresh()->balance_cents);
    }

    // ------------------------------------------------------------------
    // Laporan void dari perangkat
    // ------------------------------------------------------------------

    public function test_voided_record_voids_expired_order_and_writes_audit(): void
    {
        $order = $this->makeOrder();
        $order->update(['expires_at' => now()->subMinutes(6)]);

        $this->sync([$this->tap($order->id, 'void-1', ['status' => 'voided'])])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'void');

        $order->refresh();

        $this->assertSame(Transaction::STATUS_VOIDED, $order->status);
        $this->assertSame('QUEUE_EXPIRED', $order->error_code);
        $this->assertNotNull($order->voided_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'transaction.voided_queue_expired',
        ]);
    }

    public function test_voided_record_does_not_cancel_a_still_valid_order(): void
    {
        // Jam perangkat yang salah tidak boleh membatalkan order yang masih
        // dalam masa tap; scheduler yang akan membatalkannya bila memang basi.
        $order = $this->makeOrder();

        $this->sync([$this->tap($order->id, 'void-fresh', ['status' => 'voided'])])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'void');

        $this->assertSame(Transaction::STATUS_AWAITING_RFID, $order->refresh()->status);
    }

    public function test_voided_record_after_payment_is_reported_as_duplicate(): void
    {
        $order = $this->makeOrder();

        $this->sync([$this->tap($order->id, 'bayar-dulu')])
            ->assertJsonPath('data.results.0.status', 'success');

        $order->update(['expires_at' => now()->subMinutes(6)]);

        $this->sync([$this->tap($order->id, 'void-terlambat', ['status' => 'voided'])])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'duplicate');

        $this->assertSame(Transaction::STATUS_COMPLETED, $order->refresh()->status);
        $this->assertSame(85000, $this->buyer->fresh()->balance_cents);
    }

    // ------------------------------------------------------------------
    // Metadata untuk perangkat
    // ------------------------------------------------------------------

    public function test_sync_returns_server_epoch_for_device_clock(): void
    {
        $response = $this->sync([])->assertOk();

        $response->assertJsonStructure([
            'data' => ['server_time', 'server_epoch', 'results', 'queue_commands'],
        ]);

        $this->assertEqualsWithDelta(time(), $response->json('data.server_epoch'), 60);
    }

    public function test_queue_counters_are_stored_and_zero_is_not_ignored(): void
    {
        $this->sync([], ['queue_pending' => 3, 'queue_failed' => 1])->assertOk();

        $this->console->refresh();
        $this->assertSame(3, $this->console->queue_pending);
        $this->assertSame(1, $this->console->queue_failed);
        $this->assertNotNull($this->console->queue_reported_at);

        $this->sync([], ['queue_pending' => 0, 'queue_failed' => 0])->assertOk();

        $this->assertSame(0, $this->console->refresh()->queue_pending);
        $this->assertSame(0, $this->console->queue_failed);
    }

    public function test_sync_marks_console_online(): void
    {
        $this->console->update(['status' => 'offline']);

        $this->sync([], ['queue_pending' => 0])->assertOk();

        $this->console->refresh();
        $this->assertSame('online', $this->console->status);
        $this->assertNotNull($this->console->last_heartbeat_at);
    }

    // ------------------------------------------------------------------
    // Kriteria penerimaan: 50 tap < 10 detik
    // ------------------------------------------------------------------

    public function test_batch_broadcasts_are_coalesced_into_a_single_http_request(): void
    {
        $taps = [];

        for ($i = 0; $i < 5; $i++) {
            $order = $this->makeOrder(1000);
            $taps[] = $this->tap($order->id, "gabung-{$i}");
        }

        $this->resetHttpRecorder();

        $this->sync($taps)->assertOk();

        // Semua broadcast tap harus berada dalam SATU request. Request lain
        // (mis. `console.status` dari update heartbeat) boleh ada.
        $batchRequests = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn ($request) => count($request->data()['messages'] ?? []) === 10)
            ->values();

        $this->assertCount(1, $batchRequests);

        $tapEvents = collect($batchRequests[0]->data()['messages'])
            ->whereIn('event', ['completed', 'transaction.created']);

        $this->assertCount(10, $tapEvents);
    }

    public function test_fifty_taps_are_processed_in_one_batch_under_ten_seconds(): void
    {
        $taps = [];

        for ($i = 0; $i < 50; $i++) {
            $order = $this->makeOrder(1000);
            $taps[] = $this->tap($order->id, "massal-{$i}");
        }

        $started = microtime(true);
        $response = $this->sync($taps);
        $elapsed = microtime(true) - $started;

        $response->assertOk();
        $this->assertCount(50, $response->json('data.results'));
        $this->assertSame(
            ['success'],
            array_values(array_unique(array_column($response->json('data.results'), 'status')))
        );

        $this->assertSame(50000, $this->buyer->fresh()->balance_cents);
        $this->assertLessThan(10.0, $elapsed, "Batch 50 tap memakan {$elapsed} detik.");
    }
}
