<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Console\SyncController as ConsoleSyncController;
use App\Http\Controllers\Api\Console\TransactionController as ConsoleTransactionController;
use App\Http\Controllers\Cashier\OrderController;

// Cashier API Routes (Sanctum token auth + cashier middleware)
Route::prefix('cashier')->middleware(['auth:sanctum', 'cashier'])->group(function () {
    Route::get('/menu', [OrderController::class, 'menu'])->name('cashier.menu');

    Route::prefix('orders')->name('cashier.orders.')->group(function () {
        Route::post('/', [OrderController::class, 'store'])->name('store');
        Route::get('/{id}/status', [OrderController::class, 'status'])->name('status');
        Route::post('/{id}/cancel', [OrderController::class, 'cancel'])->name('cancel');
    });
});

// Cashier login (public, no auth middleware)
Route::prefix('cashier')->group(function () {
    Route::post('/login', [OrderController::class, 'login'])->name('cashier.login');
});

// Console (hardware) API Routes — X-Device-ID + X-API-Key
Route::prefix('console')->middleware('console.auth')->group(function () {
    Route::post('/rfid-tap', [ConsoleTransactionController::class, 'rfidTap'])->name('console.rfid-tap');
    Route::get('/pending-order', [ConsoleTransactionController::class, 'pendingOrder'])->name('console.pending-order');
    Route::get('/balance/{uid}', [ConsoleTransactionController::class, 'balance'])
        ->where('uid', '[0-9A-Fa-f]{8}')
        ->name('console.balance');
    Route::post('/heartbeat', [ConsoleTransactionController::class, 'heartbeat'])->name('console.heartbeat');

    // Ticket 10: kirim antrean tap offline sekaligus, lalu ack perintah admin.
    Route::post('/sync', [ConsoleSyncController::class, 'sync'])->name('console.sync');
    Route::post('/commands/ack', [ConsoleSyncController::class, 'acknowledgeCommands'])
        ->name('console.commands.ack');
});