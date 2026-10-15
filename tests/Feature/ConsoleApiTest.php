<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Console;
use App\Models\ConsoleCredential;
use App\Models\MenuItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsoleApiTest extends TestCase
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

    private function headers(?string $deviceId = null, ?string $apiKey = null): array
    {
        return array_filter([
            'X-Device-ID' => $deviceId ?? $this->console->device_id,
            'X-API-Key' => $apiKey ?? $this->apiKey,
        ], fn ($v) => $v !== null);
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
            'unit_price_cents' => 15000,
        ]);

        return $order;
    }

    // ------------------------------------------------------------------
    // Middleware ConsoleAuth
    // ------------------------------------------------------------------

    public function test_request_without_headers_is_rejected(): void
    {
        $this->postJson('/api/console/rfid-tap', [])->assertUnauthorized();
    }

    public function test_request_with_only_device_id_is_rejected(): void
    {
        $this->withHeaders(['X-Device-ID' => $this->console->device_id])
            ->postJson('/api/console/rfid-tap', [])
            ->assertUnauthorized();
    }

    public function test_wrong_api_key_is_rejected(): void
    {
        $order = $this->makeOrder();

        $this->withHeaders($this->headers(apiKey: 'wrong-key'))
            ->postJson('/api/console/rfid-tap', [
                'rfid_uid' => 'A1B2C3D4',
                'order_id' => $order->id,
            ])
            ->assertUnauthorized();

        $this->assertSame(Transaction::STATUS_AWAITING_RFID, $order->refresh()->status);
    }

    public function test_unknown_device_is_rejected(): void
    {
        $this->withHeaders($this->headers(deviceId: 'ESP-000000000000'))
            ->postJson('/api/console/rfid-tap', ['rfid_uid' => 'A1B2C3D4'])
            ->assertUnauthorized();
    }

    public function test_inactive_console_is_rejected(): void
    {
        $this->console->update(['is_active' => false]);

        $this->withHeaders($this->headers())
            ->postJson('/api/console/rfid-tap', ['rfid_uid' => 'A1B2C3D4'])
            ->assertUnauthorized();
    }

    // ------------------------------------------------------------------
    // POST /api/console/rfid-tap
    // ------------------------------------------------------------------

    public function test_valid_tap_returns_receipt_and_new_balance(): void
    {
        $order = $this->makeOrder();

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/console/rfid-tap', [
                'rfid_uid' => 'A1B2C3D4',
                'order_id' => $order->id,
                'idempotency_key' => 'tap-abc-1',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('data.status', Transaction::STATUS_COMPLETED)
            ->assertJsonPath('data.new_balance_cents', 85000)
            ->assertJsonPath('data.total_cents', 15000)
            ->assertJsonStructure(['data' => ['receipt_no', 'buyer_name', 'completed_at']]);

        $this->assertMatchesRegularExpression(
            '/^TXN-\d{8}-\d{4}$/',
            $response->json('data.receipt_no')
        );
    }

    public function test_duplicate_tap_returns_same_receipt(): void
    {
        $order = $this->makeOrder();
        $payload = ['rfid_uid' => 'A1B2C3D4', 'order_id' => $order->id, 'idempotency_key' => 'tap-dup-1'];

        $first = $this->withHeaders($this->headers())->postJson('/api/console/rfid-tap', $payload);
        $second = $this->withHeaders($this->headers())->postJson('/api/console/rfid-tap', $payload);

        $second->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('data.receipt_no', $first->json('data.receipt_no'))
            ->assertJsonPath('data.new_balance_cents', 85000);

        // Saldo hanya berkurang sekali.
        $this->assertSame(85000, $this->buyer->fresh()->balance_cents);
    }

    public function test_invalid_rfid_returns_404(): void
    {
        $order = $this->makeOrder();

        $this->withHeaders($this->headers())
            ->postJson('/api/console/rfid-tap', [
                'rfid_uid' => 'DEADBEEF',
                'order_id' => $order->id,
            ])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'card_invalid');

        $this->assertSame(100000, $this->buyer->fresh()->balance_cents);
    }

    public function test_insufficient_balance_returns_422(): void
    {
        $this->buyer->update(['balance_cents' => 1000]);
        $order = $this->makeOrder();

        $this->withHeaders($this->headers())
            ->postJson('/api/console/rfid-tap', [
                'rfid_uid' => 'A1B2C3D4',
                'order_id' => $order->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'insufficient_balance');

        $this->assertSame(1000, $this->buyer->fresh()->balance_cents);
    }

    public function test_tap_on_already_completed_order_returns_422(): void
    {
        $order = $this->makeOrder();
        $this->withHeaders($this->headers())->postJson('/api/console/rfid-tap', [
            'rfid_uid' => 'A1B2C3D4', 'order_id' => $order->id,
        ]);

        $this->withHeaders($this->headers())
            ->postJson('/api/console/rfid-tap', [
                'rfid_uid' => 'A1B2C3D4', 'order_id' => $order->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'order_not_tappable');

        $this->assertSame(85000, $this->buyer->fresh()->balance_cents);
    }

    public function test_malformed_rfid_is_rejected_by_validation(): void
    {
        $order = $this->makeOrder();

        $this->withHeaders($this->headers())
            ->postJson('/api/console/rfid-tap', [
                'rfid_uid' => 'not-hex!',
                'order_id' => $order->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rfid_uid');
    }

    // ------------------------------------------------------------------
    // GET /api/console/balance/{uid}
    // ------------------------------------------------------------------

    public function test_balance_endpoint_returns_card_owner_and_balance(): void
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/console/balance/A1B2C3D4')
            ->assertOk()
            ->assertJsonPath('data.balance_cents', 100000)
            ->assertJsonPath('data.name', $this->buyer->name);
    }

    public function test_balance_endpoint_404_for_unknown_card(): void
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/console/balance/DEADBEEF')
            ->assertNotFound()
            ->assertJsonPath('error_code', 'card_invalid');
    }

    // ------------------------------------------------------------------
    // POST /api/console/heartbeat
    // ------------------------------------------------------------------

    public function test_heartbeat_updates_console_status(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/console/heartbeat', [
                'ip_address' => '192.168.1.50',
                'wifi_rssi' => -57,
                'firmware_version' => '1.0.0',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['server_time', 'queue_commands']]);

        $this->console->refresh();

        $this->assertSame('online', $this->console->status);
        $this->assertSame('1.0.0', $this->console->firmware_version);
        $this->assertSame(-57, $this->console->wifi_rssi);
        $this->assertNotNull($this->console->last_heartbeat_at);
    }

    public function test_heartbeat_rejects_out_of_range_rssi(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/console/heartbeat', ['wifi_rssi' => -999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('wifi_rssi');
    }
}