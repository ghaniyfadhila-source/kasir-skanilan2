<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Console;
use App\Models\MenuItem;
use App\Observers\AuditObserver;
use App\Services\SupabaseEnv;
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
    }
}