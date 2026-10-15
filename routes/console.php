<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ticket 08: order `awaiting_rfid` yang lewat 60 detik dibatalkan otomatis.
Schedule::command('orders:void-expired')->everyMinute()->withoutOverlapping();