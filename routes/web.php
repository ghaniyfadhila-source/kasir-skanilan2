<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\ConsoleController;
use App\Http\Controllers\Admin\ForgotPasswordController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\PublicPasswordResetController;

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

        // User Management Routes
        Route::resource('users', UserController::class)->except(['destroy']);
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        // RFID Card Routes
        Route::post('/users/{user}/rfid', [UserController::class, 'storeRfid'])->name('users.rfid.store');
        Route::delete('/users/{user}/rfid/{rfidCard}', [UserController::class, 'destroyRfid'])->name('users.rfid.destroy');

        // Balance Top-up
        Route::post('/users/{user}/topup', [UserController::class, 'topUp'])->name('users.topup');

        // Reports Routes
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/', [ReportController::class, 'index'])->name('index');
            Route::get('/data', [ReportController::class, 'data'])->name('data');
            Route::get('/export', [ReportController::class, 'export'])->name('export');
        });

        // Audit Log Routes
        Route::prefix('audit-logs')->name('audit-logs.')->group(function () {
            Route::get('/', [AuditLogController::class, 'index'])->name('index');
            Route::get('/export', [AuditLogController::class, 'export'])->name('export');
        });

        // Settings Routes
        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/', [SettingController::class, 'index'])->name('index');
            Route::put('/', [SettingController::class, 'update'])->name('update');
            Route::put('/password', [SettingController::class, 'updatePassword'])->name('password');
        });

        // Password Reset Routes (admin membuat kode untuk user lain)
        Route::prefix('password-resets')->name('password-resets.')->group(function () {
            Route::get('/', [ForgotPasswordController::class, 'create'])->name('create');
            Route::post('/', [ForgotPasswordController::class, 'store'])->name('store');
            Route::delete('/{user}', [ForgotPasswordController::class, 'destroy'])->name('destroy');
        });
    });
});

// Cashier Web UI Routes
Route::prefix('cashier')->name('cashier.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', function () {
            $consoles = \App\Models\Console::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'device_id', 'name']);
            return view('cashier.login', compact('consoles'));
        })->name('login');
        
        Route::post('/login', function (Illuminate\Http\Request $request) {
            $request->validate([
                'device_id' => 'required|exists:consoles,device_id',
                'password' => 'required|string',
            ]);
            
            // This will be handled by the frontend calling the API
            return redirect()->route('cashier.menu')->with('device_id', $request->device_id);
        })->name('login.post');
    });

    Route::middleware(['auth:sanctum', 'cashier'])->group(function () {
        Route::get('/menu', function () {
            return view('cashier.menu');
        })->name('menu');

        Route::get('/awaiting/{orderId}', function (string $orderId) {
            return view('cashier.awaiting', compact('orderId'));
        })->name('awaiting');

        Route::get('/success/{orderId}', function (string $orderId) {
            return view('cashier.success', compact('orderId'));
        })->name('success');
    });
});

// Alias top-level untuk middleware auth (nama route "login" yang dicari
// Laravel). Langsung merender form login admin agar tidak ada redirect ganda.
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->name('login.post.alias');

// Reset password publik: tanpa login, memakai kode 6 digit dari admin.
Route::prefix('password')->name('password.')->group(function () {
    Route::get('/reset', [PublicPasswordResetController::class, 'show'])->name('reset');
    Route::post('/reset', [PublicPasswordResetController::class, 'verify'])->name('reset.verify');
});