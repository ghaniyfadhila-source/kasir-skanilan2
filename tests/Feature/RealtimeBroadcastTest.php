<?php

namespace Tests\Feature;

use App\Events\ConsoleStatusChanged;
use App\Events\MenuItemChanged;
use App\Events\OrderPlaced;
use App\Events\OrderVoided;
use App\Events\TransactionCompleted;
use App\Models\Category;
use App\Models\Console;
use App\Models\ConsoleCredential;
use App\Models\MenuItem;
use App\Models\RfidCard;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Realtime\RealtimePublisher;
use App\Services\Realtime\RealtimeTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Test pengiriman event Realtime.
 *
 * Yang diuji adalah bentuk request yang dikirim ke Supabase dan topik yang
 * dipilih, bukan respons server - yang sungguhan diuji oleh
 * `php artisan realtime:listen` yang mendengarkan lewat WebSocket.
 */
class RealtimeBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'ESP-AABBCCDDEEFF';

    private const SECRET = 'abcdef0123456789abcdef0123456789';

    /**
     * Status yang dikembalikan server untuk request broadcast.
     *
     * Diatur lewat properti, bukan `Http::fake()` terpisah per test: stub
     * Http menumpuk, dan stub pertama yang cocok yang menang, jadi memanggil
     * `Http::fake()` lagi di dalam test tidak menggantinya.
     */
    private int $broadcastStatus = 202;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('realtime.channel_secret', self::SECRET);
        config()->set('realtime.url', 'https://contoh.supabase.co');
        config()->set('realtime.client_key', 'sb_publishable_uji');
        config()->set('realtime.server_key', 'sb_secret_uji');

        Http::fake([
            '*/realtime/v1/api/broadcast' => function () {
                return Http::response(['message_ids' => ['m1']], $this->broadcastStatus);
            },
        ]);
    }

    /**
     * Buang rekaman request sebelumnya.
     *
     * Dipakai test yang helper-nya membuat model: membuat kategori atau menu
     * juga memicu broadcast, sehingga jumlah request tidak lagi mencerminkan
     * hanya event yang sedang diuji.
     *
     * `Http::fake()` menumpuk stub dan `clearResolvedInstances()` tidak
     * mengosongkan rekaman, jadi satu-satunya cara yang bersih adalah
     * mengganti factory-nya.
     */
    private function resetRecordedRequests(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory($this->app['events']));

        Http::fake([
            '*/realtime/v1/api/broadcast' => function () {
                return Http::response(['message_ids' => ['m1']], $this->broadcastStatus);
            },
        ]);
    }

    // ---------------------------------------------------------------- publisher

    public function test_it_posts_to_the_current_broadcast_endpoint(): void
    {
        app(RealtimePublisher::class)->broadcast('admin:dashboard:'.$this::SECRET, 'probe', ['a' => 1]);

        Http::assertSent(function ($request) {
            $this->assertSame('https://contoh.supabase.co/realtime/v1/api/broadcast', $request->url());
            $this->assertSame('sb_secret_uji', $request->header('apikey')[0]);

            $body = $request->data();

            $this->assertCount(1, $body['messages']);
            $this->assertSame('probe', $body['messages'][0]['event']);
            $this->assertSame(['a' => 1], $body['messages'][0]['payload']);

            return true;
        });
    }

    public function test_a_rejected_broadcast_returns_false_without_throwing(): void
    {
        $this->broadcastStatus = 500;

        $ok = app(RealtimePublisher::class)->broadcast('topik', 'probe', []);

        $this->assertFalse($ok, 'Broadcast gagal tidak boleh melempar exception; kasir harus tetap bisa berjualan.');
    }

    public function test_it_does_nothing_when_realtime_is_not_configured(): void
    {
        config()->set('realtime.channel_secret', null);

        $ok = app(RealtimePublisher::class)->broadcast('topik', 'probe', []);

        $this->assertFalse($ok);
        Http::assertNothingSent();
    }

    // ----------------------------------------------------------------- listeners

    public function test_completed_event_reaches_cashier_and_admin(): void
    {
        [$transaction] = $this->completedTransaction();

        TransactionCompleted::dispatch($transaction);

        Http::assertSent(fn ($r) => $this->messageTo($r)['event'] === 'completed'
            && $this->messageTo($r)['topic'] === RealtimeTopic::cashier(self::DEVICE));

        Http::assertSent(fn ($r) => $this->messageTo($r)['event'] === 'transaction.created'
            && $this->messageTo($r)['topic'] === RealtimeTopic::adminDashboard());
    }

    public function test_completed_payload_contains_formatted_amounts(): void
    {
        [$transaction] = $this->completedTransaction();

        TransactionCompleted::dispatch($transaction);

        Http::assertSent(function ($request) {
            $message = $this->messageTo($request);

            if ($message['event'] !== 'completed') {
                return false;
            }

            // Kemarin field ini null karena nama accessor-nya salah ketik
            // (`total_formatted` padahal accessor-nya `formatted_total`).
            $this->assertSame('Rp 15.000', $message['payload']['total_formatted']);
            $this->assertIsString($message['payload']['new_balance_formatted']);
            $this->assertNotNull($message['payload']['receipt_no']);

            return true;
        });
    }

    public function test_voided_event_reaches_cashier_and_admin(): void
    {
        $order = $this->awaitingOrder();

        OrderVoided::dispatch($order, 'kedaluwarsa');

        Http::assertSent(fn ($r) => $this->messageTo($r)['event'] === 'void'
            && $this->messageTo($r)['topic'] === RealtimeTopic::cashier(self::DEVICE));

        Http::assertSent(fn ($r) => $this->messageTo($r)['event'] === 'order.voided');
    }

    public function test_placed_event_goes_to_admin_only(): void
    {
        OrderPlaced::dispatch($this->awaitingOrder());

        Http::assertSent(fn ($r) => $this->messageTo($r)['event'] === 'order.placed');

        // Kasir yang membuat ordernya sendiri sudah tahu; mengirim ulang hanya
        // menambah beban tanpa manfaat.
        Http::assertNotSent(fn ($r) => $this->messageTo($r)['event'] === 'awaiting_rfid');
    }

    public function test_menu_change_reaches_every_active_console(): void
    {
        $this->console();
        $this->console('ESP-KEDUA');

        $item = MenuItem::create([
            'name' => 'Nasi Goreng',
            'price_cents' => 1500000,
            'stock' => -1,
            'is_active' => true,
        ]);

        MenuItemChanged::dispatch($item, 'updated');

        Http::assertSent(fn ($r) => $this->messageTo($r)['event'] === 'menu.updated'
            && $this->messageTo($r)['topic'] === RealtimeTopic::cashier(self::DEVICE));

        Http::assertSent(fn ($r) => $this->messageTo($r)['event'] === 'menu.updated'
            && $this->messageTo($r)['topic'] === RealtimeTopic::cashier('ESP-KEDUA'));
    }

    public function test_console_status_change_goes_to_admin(): void
    {
        ConsoleStatusChanged::dispatch($this->console());

        Http::assertSent(fn ($r) => $this->messageTo($r)['event'] === 'console.status');
    }

    /**
     * Regresi auto-discovery: dengan listener terdaftar dua kali, semua test
     * di atas tetap lulus karena hanya memeriksa "ada yang terkirim".
     */
    public function test_each_event_produces_exactly_one_broadcast(): void
    {
        // Helper-nya membuat kategori dan menu, yang keduanya ikut menyiarkan.
        // Rekamannya dibuang supaya yang dihitung hanya event di bawah ini.
        $order = $this->awaitingOrder();

        $this->resetRecordedRequests();

        OrderPlaced::dispatch($order);

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $this->messageTo($r)['event'] === 'order.placed');
    }

    // ------------------------------------------------------------------ observer

    public function test_menu_observer_skips_changes_that_are_not_visible_to_cashiers(): void
    {
        $item = MenuItem::create([
            'name' => 'Es Teh',
            'price_cents' => 500000,
            'stock' => -1,
            'is_active' => true,
        ]);

        // `create` memang ikut menyiarkan; yang diuji adalah `update`.
        Http::assertSent(fn ($r) => $this->messageTo($r)['event'] === 'menu.updated');

        // `sort_order` tidak ditampilkan di layar kasir, jadi tidak perlu
        // menembus jaringan.
        $this->resetRecordedRequests();

        $item->update(['sort_order' => 7]);
        $item->refresh();

        Http::assertSentCount(0);

        // Harga berubah: harus sampai.
        $this->resetRecordedRequests();

        $item->update(['price_cents' => 600000]);
        $item->refresh();

        Http::assertSent(fn ($r) => $this->messageTo($r)['event'] === 'menu.updated');
    }

    // ------------------------------------------------------------------- helper

    /**
     * @return array<string, mixed>
     */
    private function messageTo($request): array
    {
        return $request->data()['messages'][0] ?? [];
    }

    private function console(string $deviceId = self::DEVICE): Console
    {
        $console = Console::firstOrCreate(
            ['device_id' => $deviceId],
            ['name' => 'Kasir '.$deviceId, 'status' => 'online', 'is_active' => true]
        );

        ConsoleCredential::firstOrCreate(
            ['console_id' => $console->id],
            ['password_hash' => Hash::make('kasir12345'), 'api_key' => ConsoleCredential::generateApiKey()]
        );

        return $console->fresh();
    }

    private function awaitingOrder(): Transaction
    {
        $console = $this->console();
        $category = Category::create(['name' => 'Makanan', 'sort_order' => 1]);
        $item = MenuItem::create([
            'category_id' => $category->id,
            'name' => 'Nasi Goreng',
            'price_cents' => 1500000,
            'stock' => -1,
            'is_active' => true,
        ]);

        $order = Transaction::create([
            'console_id' => $console->id,
            'total_cents' => 1500000,
            'status' => Transaction::STATUS_AWAITING_RFID,
            'expires_at' => now()->addMinute(),
            'idempotency_key' => 'uji-'.Transaction::generateReceiptNo(),
        ]);

        $order->transactionItems()->create(['menu_item_id' => $item->id, 'quantity' => 1, 'unit_price_cents' => 1500000]);

        return $order->fresh(['console']);
    }

    /**
     * @return array{0: Transaction}
     */
    private function completedTransaction(): array
    {
        $order = $this->awaitingOrder();

        $buyer = User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi@uji.local',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'balance_cents' => 1000000,
        ]);

        RfidCard::create([
            'user_id' => $buyer->id,
            'rfid_uid' => 'A1B2C3D4',
            'is_active' => true,
        ]);

        $order->forceFill([
            'user_id' => $buyer->id,
            'status' => Transaction::STATUS_COMPLETED,
            'completed_at' => now(),
            'receipt_no' => Transaction::generateReceiptNo(),
        ])->save();

        return [$order->fresh(['buyer', 'console'])];
    }
}