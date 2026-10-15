<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Console;
use App\Models\MenuItem;
use App\Models\RfidCard;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RfidTapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RfidTapServiceTest extends TestCase
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

        $category = Category::factory()->create();
        $this->menuItem = MenuItem::factory()->create([
            'category_id' => $category->id,
            'price_cents' => 15000,
            'stock' => -1, // unlimited
            'is_active' => true,
        ]);

        $this->buyer = User::factory()->create([
            'role' => 'student',
            'balance_cents' => 100000,
        ]);

        $this->buyer->assignRfid('A1B2C3D4');
    }

    private function makeOrder(int $totalCents = 15000, ?string $consoleId = null): Transaction
    {
        $order = Transaction::create([
            'console_id' => $consoleId ?? $this->console->id,
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
    // Happy path
    // ------------------------------------------------------------------

    public function test_valid_tap_completes_order_and_debits_balance(): void
    {
        $order = $this->makeOrder(15000);

        $result = $this->service()->settle(
            $this->console->id,
            'a1b2c3d4', // lowercase harus tetap diterima
            $order->id
        );

        $this->assertSame(RfidTapService::OUTCOME_COMPLETED, $result['outcome']);

        $order->refresh();

        $this->assertSame(Transaction::STATUS_COMPLETED, $order->status);
        $this->assertSame($this->buyer->id, $order->user_id);
        $this->assertNotNull($order->completed_at);
        $this->assertNotNull($order->synced_at);
        $this->assertMatchesRegularExpression('/^TXN-\d{8}-\d{4}$/', $order->receipt_no);

        // 100000 - 15000 = 85000
        $this->assertSame(85000, $this->buyer->fresh()->balance_cents);
    }

    public function test_tap_creates_audit_log(): void
    {
        $order = $this->makeOrder();

        $this->service()->settle($this->console->id, 'A1B2C3D4', $order->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'transaction.completed',
            'console_id' => $this->console->id,
            'user_id' => $this->buyer->id,
        ]);
    }

    public function test_receipt_numbers_increment_within_a_day(): void
    {
        $first = $this->makeOrder();
        $this->service()->settle($this->console->id, 'A1B2C3D4', $first->id);

        $second = $this->makeOrder();
        $this->service()->settle($this->console->id, 'A1B2C3D4', $second->id);

        $receipts = Transaction::orderBy('receipt_no')->pluck('receipt_no')->all();

        $this->assertCount(2, $receipts);
        $this->assertNotSame($receipts[0], $receipts[1]);
    }

    public function test_unlimited_stock_is_not_decremented(): void
    {
        $order = $this->makeOrder();

        $this->service()->settle($this->console->id, 'A1B2C3D4', $order->id);

        $this->assertSame(-1, $this->menuItem->fresh()->stock);
    }

    public function test_limited_stock_is_decremented_on_tap(): void
    {
        $this->menuItem->update(['stock' => 5]);
        $order = $this->makeOrder();

        $this->service()->settle($this->console->id, 'A1B2C3D4', $order->id);

        $this->assertSame(4, $this->menuItem->fresh()->stock);
    }

    // ------------------------------------------------------------------
    // Idempotency
    // ------------------------------------------------------------------

    public function test_repeated_tap_with_same_key_returns_duplicate_without_double_charge(): void
    {
        $order = $this->makeOrder();
        $key = 'tap-'.Str::uuid();

        $first = $this->service()->settle($this->console->id, 'A1B2C3D4', $order->id, $key);
        $second = $this->service()->settle($this->console->id, 'A1B2C3D4', $order->id, $key);

        $this->assertSame(RfidTapService::OUTCOME_COMPLETED, $first['outcome']);
        $this->assertSame(RfidTapService::OUTCOME_DUPLICATE, $second['outcome']);

        // Saldo hanya terpotong sekali.
        $this->assertSame(85000, $this->buyer->fresh()->balance_cents);
    }

    // ------------------------------------------------------------------
    // rejections
    // ------------------------------------------------------------------

    public function test_unknown_rfid_is_rejected(): void
    {
        $order = $this->makeOrder();

        $result = $this->service()->settle($this->console->id, 'DEADBEEF', $order->id);

        $this->assertSame(RfidTapService::OUTCOME_CARD_INVALID, $result['outcome']);
        $this->assertSame(Transaction::STATUS_AWAITING_RFID, $order->refresh()->status);
        $this->assertSame(100000, $this->buyer->fresh()->balance_cents);
    }

    public function test_revoked_card_cannot_pay(): void
    {
        $order = $this->makeOrder();
        $this->buyer->revokeRfid($this->buyer->rfidCards()->first()->id);

        $result = $this->service()->settle($this->console->id, 'A1B2C3D4', $order->id);

        $this->assertSame(RfidTapService::OUTCOME_CARD_INVALID, $result['outcome']);
        $this->assertSame(100000, $this->buyer->fresh()->balance_cents);
    }

    public function test_inactive_card_cannot_pay(): void
    {
        $order = $this->makeOrder();
        RfidCard::where('rfid_uid', 'A1B2C3D4')->update(['is_active' => false]);

        $result = $this->service()->settle($this->console->id, 'A1B2C3D4', $order->id);

        $this->assertSame(RfidTapService::OUTCOME_CARD_INVALID, $result['outcome']);
    }

    public function test_insufficient_balance_is_rejected_and_order_stays_open(): void
    {
        $this->buyer->update(['balance_cents' => 5000]);
        $order = $this->makeOrder(15000);

        $result = $this->service()->settle($this->console->id, 'A1B2C3D4', $order->id);

        $this->assertSame(RfidTapService::OUTCOME_BALANCE_INSUFFICIENT, $result['outcome']);
        $this->assertSame(5000, $this->buyer->fresh()->balance_cents);
        $this->assertSame(Transaction::STATUS_AWAITING_RFID, $order->refresh()->status);
    }

    public function test_tap_on_completed_order_is_rejected(): void
    {
        $order = $this->makeOrder();
        $this->service()->settle($this->console->id, 'A1B2C3D4', $order->id);

        $second = $this->service()->settle($this->console->id, 'A1B2C3D4', $order->id);

        $this->assertSame(RfidTapService::OUTCOME_ORDER_NOT_TAPPABLE, $second['outcome']);
        $this->assertSame(85000, $this->buyer->fresh()->balance_cents);
    }

    public function test_tap_on_voided_order_is_rejected(): void
    {
        $order = $this->makeOrder();
        $order->update(['status' => Transaction::STATUS_VOIDED, 'voided_at' => now()]);

        $result = $this->service()->settle($this->console->id, 'A1B2C3D4', $order->id);

        $this->assertSame(RfidTapService::OUTCOME_ORDER_NOT_TAPPABLE, $result['outcome']);
        $this->assertSame(100000, $this->buyer->fresh()->balance_cents);
    }

    public function test_tap_on_expired_order_is_rejected(): void
    {
        $order = $this->makeOrder();
        $order->update(['expires_at' => now()->subSecond()]);

        $result = $this->service()->settle($this->console->id, 'A1B2C3D4', $order->id);

        $this->assertSame(RfidTapService::OUTCOME_ORDER_NOT_TAPPABLE, $result['outcome']);
        $this->assertSame(100000, $this->buyer->fresh()->balance_cents);
    }

    public function test_tap_on_other_consoles_order_is_rejected(): void
    {
        $otherConsole = Console::factory()->create(['is_active' => true]);
        $order = $this->makeOrder(15000, $otherConsole->id);

        $result = $this->service()->settle($this->console->id, 'A1B2C3D4', $order->id);

        $this->assertSame(RfidTapService::OUTCOME_ORDER_NOT_TAPPABLE, $result['outcome']);
        $this->assertSame(100000, $this->buyer->fresh()->balance_cents);
    }

    public function test_tap_is_rejected_when_stock_ran_out(): void
    {
        $this->menuItem->update(['stock' => 0]);
        $order = $this->makeOrder();

        $result = $this->service()->settle($this->console->id, 'A1B2C3D4', $order->id);

        $this->assertSame(RfidTapService::OUTCOME_STOCK_INSUFFICIENT, $result['outcome']);
        $this->assertSame(100000, $this->buyer->fresh()->balance_cents);
        $this->assertSame(Transaction::STATUS_AWAITING_RFID, $order->refresh()->status);
    }

    public function test_tap_is_rejected_when_menu_became_inactive(): void
    {
        $this->menuItem->update(['is_active' => false]);
        $order = $this->makeOrder();

        $result = $this->service()->settle($this->console->id, 'A1B2C3D4', $order->id);

        $this->assertSame(RfidTapService::OUTCOME_STOCK_INSUFFICIENT, $result['outcome']);
        $this->assertSame(100000, $this->buyer->fresh()->balance_cents);
    }

    // ------------------------------------------------------------------
    // state helpers
    // ------------------------------------------------------------------

    public function test_can_be_paid_by_tap_requires_awaiting_and_not_expired(): void
    {
        $order = $this->makeOrder();

        $this->assertTrue($order->canBePaidByTap());

        $order->expires_at = now()->subSecond();
        $this->assertFalse($order->canBePaidByTap());

        $order->expires_at = now()->addMinute();
        $order->status = Transaction::STATUS_COMPLETED;
        $this->assertFalse($order->canBePaidByTap());
    }

    private function service(): RfidTapService
    {
        return app(RfidTapService::class);
    }
}