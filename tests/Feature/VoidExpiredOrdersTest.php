<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Console;
use App\Models\MenuItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoidExpiredOrdersTest extends TestCase
{
    use RefreshDatabase;

    private Console $console;
    private User $cashier;
    private User $buyer;
    private MenuItem $menuItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->console = Console::factory()->create(['is_active' => true]);
        $this->cashier = User::factory()->create([
            'role' => 'cashier',
            'device_id' => $this->console->device_id,
        ]);

        $this->menuItem = MenuItem::factory()->create([
            'category_id' => Category::factory()->create()->id,
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

    private function makeOrder(string $status = Transaction::STATUS_AWAITING_RFID, ?int $expiresInSeconds = null): Transaction
    {
        $order = Transaction::create([
            'console_id' => $this->console->id,
            'cashier_id' => $this->cashier->id,
            'total_cents' => 15000,
            'status' => $status,
            'expires_at' => now()->addSeconds($expiresInSeconds ?? Transaction::RFID_TAP_TIMEOUT_SECONDS),
            'idempotency_key' => 'order-'.Str::uuid(),
        ]);

        $order->transactionItems()->create([
            'menu_item_id' => $this->menuItem->id,
            'quantity' => 1,
            'unit_price_cents' => 15000,
        ]);

        return $order;
    }

    public function test_expired_awaiting_order_is_voided(): void
    {
        $order = $this->makeOrder(expiresInSeconds: -10);

        $this->artisan('orders:void-expired')->assertSuccessful();

        $order->refresh();

        $this->assertSame(Transaction::STATUS_VOIDED, $order->status);
        $this->assertNotNull($order->voided_at);
        $this->assertSame('RFID_TIMEOUT', $order->error_code);
        $this->assertSame(100000, $this->buyer->fresh()->balance_cents, 'Saldo tidak boleh tersentuh saat void');
    }

    public function test_order_that_has_not_expired_is_left_alone(): void
    {
        $order = $this->makeOrder(expiresInSeconds: 30);

        $this->artisan('orders:void-expired')->assertSuccessful();

        $this->assertSame(Transaction::STATUS_AWAITING_RFID, $order->refresh()->status);
        $this->assertNull($order->voided_at);
    }

    public function test_completed_order_is_never_voided_even_if_expires_at_is_past(): void
    {
        $order = $this->makeOrder(Transaction::STATUS_COMPLETED, -10);
        $order->update(['completed_at' => now()->subMinute()]);

        $this->artisan('orders:void-expired')->assertSuccessful();

        $order->refresh();

        $this->assertSame(Transaction::STATUS_COMPLETED, $order->status);
        $this->assertNull($order->voided_at);
    }

    public function test_already_voided_order_is_not_touched_again(): void
    {
        $order = $this->makeOrder(Transaction::STATUS_VOIDED, -10);

        // Disimpan ke DB dulu supaya kita membandingkan nilai yang benar-benar
        // tersimpan (SQLite menyimpan timestamp dengan presisi detik).
        $order->update(['voided_at' => now()->subDay()]);
        $firstVoidedAt = $order->fresh()->voided_at;

        $this->artisan('orders:void-expired')->assertSuccessful();

        $this->assertEquals(
            $firstVoidedAt->toDateTimeString(),
            $order->refresh()->voided_at->toDateTimeString()
        );
    }

    public function test_voiding_creates_audit_log(): void
    {
        $order = $this->makeOrder(expiresInSeconds: -10);

        $this->artisan('orders:void-expired')->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'transaction.voided_timeout',
            'console_id' => $this->console->id,
        ]);
    }

    public function test_dry_run_does_not_change_anything(): void
    {
        $order = $this->makeOrder(expiresInSeconds: -10);

        $this->artisan('orders:void-expired --dry-run')->assertSuccessful();

        $this->assertSame(Transaction::STATUS_AWAITING_RFID, $order->refresh()->status);
    }

    public function test_voided_order_can_no_longer_be_paid_by_tap(): void
    {
        $order = $this->makeOrder(expiresInSeconds: -10);

        $this->artisan('orders:void-expired')->assertSuccessful();

        $headers = ['X-Device-ID' => $this->console->device_id];

        $credentials = \App\Models\ConsoleCredential::create([
            'console_id' => $this->console->id,
            'password_hash' => bcrypt('secret123'),
            'api_key' => 'test-key-1',
        ]);
        $headers['X-API-Key'] = $credentials->api_key;

        $this->withHeaders($headers)
            ->postJson('/api/console/rfid-tap', [
                'rfid_uid' => 'A1B2C3D4',
                'order_id' => $order->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'order_not_tappable');

        $this->assertSame(100000, $this->buyer->fresh()->balance_cents);
    }
}