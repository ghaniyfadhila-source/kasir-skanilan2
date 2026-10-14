<?php

use Illuminate\Support\Facades\Route;
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