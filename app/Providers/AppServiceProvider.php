<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Console;
use App\Models\MenuItem;
use App\Observers\AuditObserver;
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
        //
    }

    public function boot(): void
    {
        foreach (self::AUDITED_MODELS as $model) {
            $model::observe(AuditObserver::class);
        }
    }
}