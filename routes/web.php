<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\ConsoleController;
use App\Http\Controllers\Admin\MenuController;

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

        // Menu Management Routes
        Route::prefix('menu')->name('menu.')->group(function () {
            Route::get('/', [MenuController::class, 'categoriesIndex'])->name('index');
            
            // Categories
            Route::post('/categories', [MenuController::class, 'categoriesStore'])->name('categories.store');
            Route::put('/categories/{category}', [MenuController::class, 'categoriesUpdate'])->name('categories.update');
            Route::delete('/categories/{category}', [MenuController::class, 'categoriesDestroy'])->name('categories.destroy');
            Route::post('/categories/reorder', [MenuController::class, 'categoriesReorder'])->name('categories.reorder');
            
            // Menu Items
            Route::get('/items', [MenuController::class, 'itemsIndex'])->name('items.index');
            Route::get('/items/create', [MenuController::class, 'itemsCreate'])->name('items.create');
            Route::post('/items', [MenuController::class, 'itemsStore'])->name('items.store');
            Route::get('/items/{item}/edit', [MenuController::class, 'itemsEdit'])->name('items.edit');
            Route::put('/items/{item}', [MenuController::class, 'itemsUpdate'])->name('items.update');
            Route::delete('/items/{item}', [MenuController::class, 'itemsDestroy'])->name('items.destroy');
            Route::post('/items/reorder', [MenuController::class, 'itemsReorder'])->name('items.reorder');
        });
    });
});

// Fallback login route for auth middleware (redirects to admin login)
Route::get('/login', function () {
    return redirect()->route('admin.login');
})->name('login');