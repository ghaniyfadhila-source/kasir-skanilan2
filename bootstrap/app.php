<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Auto-discovery listener dimatikan.
    //
    // Discovery Laravel mendaftarkan setiap method publik yang punya satu
    // parameter, termasuk `handleCompleted`, `handleVoided`, dan `handlePlaced`.
    // Itu sebenarnya bekerja, tapi listener di Ticket 09 juga didaftarkan secara
    // eksplisit di AppServiceProvider supaya kita bisa melihat siapa mengikat
    // apa. Kalau keduanya aktif, tiap event terkirim dua kali - dan tidak ada
    // error apa pun yang menandainya.
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'cashier' => \App\Http\Middleware\CashierMiddleware::class,
            'console.auth' => \App\Http\Middleware\ConsoleAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Routenamed "login" sudah diarahkan ke halaman login admin,
        // jadi middleware auth cukup mengikuti default Laravel.
    })->create();
