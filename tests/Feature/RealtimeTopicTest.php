<?php

namespace Tests\Feature;

use App\Events\ConsoleStatusChanged;
use App\Events\MenuItemChanged;
use App\Events\OrderPlaced;
use App\Events\OrderVoided;
use App\Events\TransactionCompleted;
use App\Services\Realtime\RealtimeTopic;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Test penamaan channel.
 *
 * Nama channel adalah satu-satunya pembatas akses di desain ini, jadi test ini
 * memeriksa dua hal sekaligus: topic terangkai dengan benar, dan topik tanpa
 * secret tidak pernah bisa dipakai.
 */
class RealtimeTopicTest extends TestCase
{
    private const SECRET = 'abcdef0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('realtime.channel_secret', self::SECRET);
        config()->set('realtime.url', 'https://contoh.supabase.co');
        config()->set('realtime.client_key', 'sb_publishable_uji');
        config()->set('realtime.server_key', 'sb_secret_uji');
    }

    public function test_admin_dashboard_topic_ends_with_the_secret(): void
    {
        $this->assertSame('admin:dashboard:'.self::SECRET, RealtimeTopic::adminDashboard());
    }

    public function test_cashier_topic_contains_the_device_id(): void
    {
        $this->assertSame(
            'cashier:ESP-AABBCCDDEEFF:'.self::SECRET,
            RealtimeTopic::cashier('ESP-AABBCCDDEEFF')
        );
    }

    public function test_two_consoles_get_different_topics(): void
    {
        $this->assertNotSame(
            RealtimeTopic::cashier('ESP-AAAA'),
            RealtimeTopic::cashier('ESP-BBBB'),
            'Dua konsol tidak boleh berbagi channel; kalau sama, kasir satu melihat order kasir lain.'
        );
    }

    public function test_secret_is_never_derived_from_a_guessable_default(): void
    {
        config()->set('realtime.channel_secret', null);

        // Kosong, bukan nilai tetap. Nilai tetap yang bisa ditebak akan membuat
        // instalasi yang lupa mengisi .env terlihat berjalan padahal channelnya
        // terbuka.
        $this->assertSame('cashier:ESP-AABBCCDDEEFF:', RealtimeTopic::cashier('ESP-AABBCCDDEEFF'));
        $this->assertFalse(RealtimeTopic::isConfigured());
        $this->assertContains(
            'realtime.channel_secret (isi di .env, buat dengan php artisan supabase:configure)',
            RealtimeTopic::missingConfiguration()
        );
    }

    public function test_attach_secret_is_idempotent(): void
    {
        $this->assertSame(
            RealtimeTopic::attachSecret('admin:dashboard'),
            RealtimeTopic::attachSecret(RealtimeTopic::attachSecret('admin:dashboard')),
            'Probe dan listen harus berakhir di topic yang sama; kalau tidak, keduanya tidak akan pernah bertemu.'
        );
    }

    public function test_client_config_never_leaks_the_server_key(): void
    {
        $config = RealtimeTopic::clientConfig('ESP-AABBCCDDEEFF');

        $serialized = json_encode($config);

        $this->assertStringNotContainsString('sb_secret_uji', $serialized);
        $this->assertSame('sb_publishable_uji', $config['key']);
        $this->assertSame(
            RealtimeTopic::cashier('ESP-AABBCCDDEEFF'),
            $config['topics']['cashier']
        );
    }

    /**
     * Regresi: auto-discovery listener milik Laravel mendaftarkan
     * `BroadcastOrderEvents@handleCompleted` selain pendaftaran eksplisit di
     * AppServiceProvider. Akibatnya tiap event terkirim dua kali - tanpa error
     * apa pun, dan test suite tetap hijau.
     */
    public function test_each_event_has_exactly_one_listener(): void
    {
        foreach ([
            OrderPlaced::class,
            OrderVoided::class,
            TransactionCompleted::class,
            MenuItemChanged::class,
            ConsoleStatusChanged::class,
        ] as $event) {
            $this->assertCount(
                1,
                Event::getListeners($event),
                "Event {$event} punya lebih dari satu listener, jadi setiap event terkirim berkali-kali."
            );
        }
    }
}