<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Console;
use App\Models\MenuItem;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $console;
    protected $cashier;
    protected $category;
    protected $menuItem1;
    protected $menuItem2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin@kasir.local',
            'password' => bcrypt('password123'),
            'role' => 'admin',
        ]);

        // Create console with credentials
        $this->console = Console::factory()->create([
            'name' => 'Kasir Utama',
            'device_id' => 'ESP-A1B2C3D4E5F6',
            'is_active' => true,
        ]);

        \App\Models\ConsoleCredential::create([
            'console_id' => $this->console->id,
            'password_hash' => bcrypt('console123'),
            'api_key' => 'a1b2c3d4e5f67890',
        ]);

        // Create cashier user linked to console
        $this->cashier = User::factory()->create([
            'name' => 'Kasir Test',
            'email' => 'cashier@kasir.local',
            'password' => bcrypt('password123'),
            'role' => 'cashier',
            'device_id' => $this->console->device_id,
        ]);

        // Create category and menu items
        $this->category = Category::factory()->create(['name' => 'Makanan', 'is_active' => true]);
        $this->menuItem1 = MenuItem::factory()->create([
            'category_id' => $this->category->id,
            'name' => 'Nasi Goreng',
            'price_cents' => 1500000,
            'stock' => 20,
            'is_active' => true,
        ]);
        $this->menuItem2 = MenuItem::factory()->create([
            'category_id' => $this->category->id,
            'name' => 'Mie Goreng',
            'price_cents' => 1200000,
            'stock' => 15,
            'is_active' => true,
        ]);
    }

    // ==================== LOGIN ====================

    public function test_cashier_can_login_with_valid_credentials(): void
    {
        $response = $this->postJson('/api/cashier/login', [
            'device_id' => $this->console->device_id,
            'password' => 'console123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['token', 'device_id', 'console_name', 'cashier_name'],
            ]);
        $this->assertTrue($response->json('success'));
        $this->assertEquals($this->console->device_id, $response->json('data.device_id'));
        $this->assertEquals($this->console->name, $response->json('data.console_name'));
    }

    public function test_cashier_cannot_login_with_invalid_password(): void
    {
        $response = $this->postJson('/api/cashier/login', [
            'device_id' => $this->console->device_id,
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    public function test_cashier_cannot_login_with_invalid_device_id(): void
    {
        $response = $this->postJson('/api/cashier/login', [
            'device_id' => 'ESP-INVALID',
            'password' => 'console123',
        ]);

        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    public function test_cashier_cannot_login_inactive_console(): void
    {
        $this->console->update(['is_active' => false]);

        $response = $this->postJson('/api/cashier/login', [
            'device_id' => $this->console->device_id,
            'password' => 'console123',
        ]);

        $response->assertStatus(401);
    }

    // ==================== MENU ====================

    public function test_cashier_can_get_menu(): void
    {
        $token = $this->cashier->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/cashier/menu');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'name', 'items' => [
                        '*' => ['id', 'name', 'price_cents', 'stock', 'is_unlimited', 'image_url']
                    ]]
                ],
            ]);

        $this->assertTrue($response->json('success'));
        $categories = $response->json('data');
        $this->assertCount(1, $categories);
        $this->assertEquals('Makanan', $categories[0]['name']);
        $this->assertCount(2, $categories[0]['items']);
    }

    public function test_cashier_menu_excludes_inactive_items(): void
    {
        MenuItem::factory()->create([
            'category_id' => $this->category->id,
            'name' => 'Inactive Item',
            'price_cents' => 1000000,
            'is_active' => false,
        ]);

        $token = $this->cashier->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/cashier/menu');

        $items = $response->json('data.0.items');
        $names = collect($items)->pluck('name');
        $this->assertFalse($names->contains('Inactive Item'));
    }

    public function test_cashier_menu_excludes_out_of_stock_items(): void
    {
        MenuItem::factory()->create([
            'category_id' => $this->category->id,
            'name' => 'Out of Stock',
            'price_cents' => 1000000,
            'stock' => 0,
            'is_active' => true,
        ]);

        $token = $this->cashier->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/cashier/menu');

        $items = $response->json('data.0.items');
        $names = collect($items)->pluck('name');
        $this->assertFalse($names->contains('Out of Stock'));
    }

    public function test_cashier_menu_requires_auth(): void
    {
        $response = $this->getJson('/api/cashier/menu');
        $response->assertStatus(401);
    }

    // ==================== ORDERS ====================

    public function test_cashier_can_create_order(): void
    {
        $token = $this->cashier->createToken('test')->plainTextToken;
        $idempotencyKey = $this->console->device_id . '-' . time() . '-abcd';

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/cashier/orders', [
            'items' => [
                ['menu_item_id' => $this->menuItem1->id, 'quantity' => 2],
                ['menu_item_id' => $this->menuItem2->id, 'quantity' => 1],
            ],
            'idempotency_key' => $idempotencyKey,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => ['order_id', 'total_cents', 'total_formatted', 'status', 'expires_at'],
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertEquals('awaiting_rfid', $response->json('data.status'));
        $this->assertEquals(4200000, $response->json('data.total_cents')); // 15000*2 + 12000*1 = 42000 cents

        // Verify transaction in database
        $transaction = Transaction::where('idempotency_key', $idempotencyKey)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals('awaiting_rfid', $transaction->status);
        $this->assertEquals($this->cashier->id, $transaction->cashier_id);
        $this->assertEquals($this->console->id, $transaction->console_id);
    }

    public function test_cashier_order_idempotency(): void
    {
        $token = $this->cashier->createToken('test')->plainTextToken;
        $idempotencyKey = $this->console->device_id . '-' . time() . '-abcd';

        // First request
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/cashier/orders', [
                'items' => [['menu_item_id' => $this->menuItem1->id, 'quantity' => 1]],
                'idempotency_key' => $idempotencyKey,
            ]);

        // Second request with same key
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/cashier/orders', [
                'items' => [['menu_item_id' => $this->menuItem1->id, 'quantity' => 1]],
                'idempotency_key' => $idempotencyKey,
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'duplicate' => true]);
    }

    public function test_cashier_order_insufficient_stock(): void
    {
        $token = $this->cashier->createToken('test')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/cashier/orders', [
                'items' => [['menu_item_id' => $this->menuItem1->id, 'quantity' => 100]],
                'idempotency_key' => $this->console->device_id . '-' . time() . '-abcd',
            ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
        $this->assertStringContainsString('tidak mencukupi', $response->json('message'));
    }

    public function test_cashier_order_invalid_item(): void
    {
        $token = $this->cashier->createToken('test')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/cashier/orders', [
                'items' => [['menu_item_id' => '00000000-0000-0000-0000-000000000000', 'quantity' => 1]],
                'idempotency_key' => $this->console->device_id . '-' . time() . '-abcd',
            ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_cashier_order_requires_auth(): void
    {
        $response = $this->postJson('/api/cashier/orders', [
            'items' => [['menu_item_id' => $this->menuItem1->id, 'quantity' => 1]],
            'idempotency_key' => 'test-key',
        ]);
        $response->assertStatus(401);
    }

    public function test_cashier_can_check_order_status(): void
    {
        $token = $this->cashier->createToken('test')->plainTextToken;

        // Create order first
        $createResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/cashier/orders', [
                'items' => [['menu_item_id' => $this->menuItem1->id, 'quantity' => 1]],
                'idempotency_key' => $this->console->device_id . '-' . time() . '-abcd',
            ]);

        $orderId = $createResponse->json('data.order_id');

        // Check status
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson("/api/cashier/orders/{$orderId}/status");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['order_id', 'total_cents', 'status', 'items'],
            ]);

        $this->assertEquals('awaiting_rfid', $response->json('data.status'));
    }

    public function test_cashier_can_cancel_order(): void
    {
        $token = $this->cashier->createToken('test')->plainTextToken;

        $createResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/cashier/orders', [
                'items' => [['menu_item_id' => $this->menuItem1->id, 'quantity' => 1]],
                'idempotency_key' => $this->console->device_id . '-' . time() . '-abcd',
            ]);

        $orderId = $createResponse->json('data.order_id');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/cashier/orders/{$orderId}/cancel");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $transaction = Transaction::find($orderId);
        $this->assertEquals('voided', $transaction->status);
    }

    public function test_cashier_cannot_cancel_completed_order(): void
    {
        $token = $this->cashier->createToken('test')->plainTextToken;

        // Create a completed transaction directly
        $transaction = Transaction::create([
            'console_id' => $this->console->id,
            'cashier_id' => $this->cashier->id,
            'user_id' => User::factory()->create(['role' => 'student'])->id,
            'total_cents' => 1500000,
            'status' => Transaction::STATUS_COMPLETED,
            'idempotency_key' => 'test-completed-' . time(),
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/cashier/orders/{$transaction->id}/cancel");

        // Order-nya ada, jadi 404 menyesatkan. 422 lebih tepat: aksi valid,
        // tapi resource-nya sudah tidak dalam status yang boleh dibatalkan.
        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'order_not_cancellable');

        $this->assertSame(Transaction::STATUS_COMPLETED, $transaction->refresh()->status);
        $this->assertNull($transaction->voided_at);
    }

    public function test_cashier_order_unlimited_stock(): void
    {
        $unlimitedItem = MenuItem::factory()->create([
            'category_id' => $this->category->id,
            'name' => 'Air Mineral',
            'price_cents' => 500000,
            'stock' => -1, // unlimited
            'is_active' => true,
        ]);

        $token = $this->cashier->createToken('test')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/cashier/orders', [
                'items' => [['menu_item_id' => $unlimitedItem->id, 'quantity' => 100]],
                'idempotency_key' => $this->console->device_id . '-' . time() . '-abcd',
            ]);

        $response->assertStatus(201);
        $this->assertEquals(50000000, $response->json('data.total_cents'));
    }
}