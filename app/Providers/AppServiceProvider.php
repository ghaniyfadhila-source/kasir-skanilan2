<?php

namespace App\Providers;

use App\Events\ConsoleStatusChanged;
use App\Events\MenuItemChanged;
use App\Events\OrderPlaced;
use App\Events\OrderVoided;
use App\Events\TransactionCompleted;
use App\Listeners\BroadcastCatalogEvents;
use App\Listeners\BroadcastOrderEvents;
use App\Models\Category;
use App\Models\Console;
use App\Models\MenuItem;
use App\Observers\AuditObserver;
use App\Observers\RealtimeConsoleObserver;
use App\Observers\RealtimeMenuObserver;
use App\Services\Realtime\RealtimeTopic;
use App\Services\SupabaseEnv;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Model yang perubahannya dicatat ke `audit_logs` oleh observer.
     *
     * Dipilih di sini (bukan log manual di controller) supaya perubahan dari
     * sumber mana pun — controller, seeder, artisan command, API — ikut tercatat.
     */
    private const AUDITED_MODELS = [
        Console::class,
        MenuItem::class,
        Category::class,
    ];

    public function register(): void
    {
        // SupabaseEnv butuh path file, jadi tidak bisa di-resolve otomatis oleh
        // container. Binding di sini membuatnya bisa di-inject ke mana saja.
        //
        // Diuji, bind ke path sementara supaya test tidak menulis ke .env asli.
        // Diuji, bind ke path sementara supaya test tidak menulis ke .env asli.
        $this->app->bind(SupabaseEnv::class, function ($app): SupabaseEnv {
            $path = $app->bound('supabase.env.path')
                ? $app->make('supabase.env.path')
                : base_path('.env');

            return new SupabaseEnv($path);
        });
    }

    public function boot(): void
    {
        foreach (self::AUDITED_MODELS as $model) {
            $model::observe(AuditObserver::class);
        }

        // Realtime: hanya model yang tampil di layar kasir. `Category` sengaja tidak
        // ikut diamati; perubahan urutan kategori cukup dengan memuat ulang daftar menu.
        // tidak diteruskan lewat event - cukup dengan memuat ulang daftar menu.
        MenuItem::observe(RealtimeMenuObserver::class);
        Console::observe(RealtimeConsoleObserver::class);

        $this->registerRealtimeListeners();
        $this->shareRealtimeConfig();
    }

    /**
     * Suntikkan konfigurasi Realtime ke setiap halaman admin.
     *
     * Dipakai view composer supaya tidak setiap controller harus mengirimnya.
     * Halaman yang butuh cukup memakai `$realtime` di Blade-nya; halaman lain
     * mengabaikannya tanpa perlu tambahan apa pun.
     *
     * Admin hanya butuh channel dashboard - bukan channel kasir - sehingga
     * device id tidak ikut ke halaman.
     */
    private function shareRealtimeConfig(): void
    {
        View::composer('admin.*', function ($view): void {
            $view->with('realtime', RealtimeTopic::clientConfig());
        });
    }

    /**
     * Pasang listener Realtime (Ticket 09).
     *
     * Pendaftaran dilakukan eksplisit, bukan lewat auto-discovery, supaya
     * jelas siapa neta apa. Salah pasang di sini tidak akan kelihatan sebagai
     * error, melainkan sebagai "realtime tidak pernah jalan".
     */
    private function registerRealtimeListeners(): void
    {
        Event::listen(TransactionCompleted::class, [BroadcastOrderEvents::class, 'handleCompleted']);
        Event::listen(OrderVoided::class, [BroadcastOrderEvents::class, 'handleVoided']);
        Event::listen(OrderPlaced::class, [BroadcastOrderEvents::class, 'handlePlaced']);

        Event::listen(MenuItemChanged::class, [BroadcastCatalogEvents::class, 'handleMenuChanged']);
        Event::listen(ConsoleStatusChanged::class, [BroadcastCatalogEvents::class, 'handleConsoleChanged']);
    }
}