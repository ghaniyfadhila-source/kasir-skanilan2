<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\ConsoleController;

Route::get('/', function () {
    return view('welcome');
});

// Admin Auth Routes
Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
        Route::post('/login', [LoginController::class, 'login'])->name('login.post');
    });

    Route::middleware(['auth', 'admin'])->group(function () {
        Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
        Route::get('/dashboard', function () {
            $stats = [
                'users' => \App\Models\User::count(),
                'consoles_online' => \App\Models\Console::where('status', 'online')->count(),
                'menu_items' => \App\Models\MenuItem::where('is_active', true)->count(),
                'transactions_today' => \App\Models\Transaction::whereDate('created_at', today())->count(),
            ];
            return view('admin.dashboard', compact('stats'));
        })->name('dashboard');

        // Console Management Routes
        Route::resource('consoles', ConsoleController::class)
            ->except(['show'])
            ->parameters(['console' => 'console']);
        Route::post('/consoles/{console}/rotate-credentials', [ConsoleController::class, 'rotateCredentials'])
            ->name('consoles.rotate-credentials');
    });
});

// Fallback login route for auth middleware (redirects to admin login)
Route::get('/login', function () {
    return redirect()->route('admin.login');
})->name('login');