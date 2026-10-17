<?php

use App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API ACICU  (prefix otomatis: /api)
|--------------------------------------------------------------------------
| Publik  : config, daftar menu, buat pesanan, cek status pesanan
| Admin   : login token (Sanctum) + pembatasan peran (owner / admin)
*/

// ---------- PUBLIK (pelanggan, tanpa login) ----------
Route::get('config', [ConfigController::class, 'show']);
Route::get('products', [ProductController::class, 'index']);
Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:checkout');
Route::get('orders/{token}', [OrderController::class, 'show']);
Route::post('orders/{token}/paid', [OrderController::class, 'markPaid'])->middleware('throttle:checkout');

// ---------- ADMIN ----------
Route::prefix('admin')->group(function () {
    Route::post('login', [Admin\AuthController::class, 'login'])->middleware('throttle:admin-login');

    Route::middleware(['auth:sanctum', 'role:owner,admin'])->group(function () {
        Route::post('logout', [Admin\AuthController::class, 'logout']);
        Route::get('me', [Admin\AuthController::class, 'me']);
        Route::post('me/password', [Admin\AuthController::class, 'changePassword']);
        Route::get('dashboard', [Admin\DashboardController::class, 'index']);

        // Produk
        Route::get('products', [Admin\ProductController::class, 'index']);
        Route::post('products', [Admin\ProductController::class, 'store']);
        Route::match(['put', 'patch', 'post'], 'products/{product}', [Admin\ProductController::class, 'update']);
        Route::patch('products/{product}/stock', [Admin\ProductController::class, 'stock']);
        Route::delete('products/{product}', [Admin\ProductController::class, 'destroy']);

        // Pesanan
        Route::get('orders/export', [Admin\OrderController::class, 'export']);
        Route::get('orders', [Admin\OrderController::class, 'index']);
        Route::get('orders/{order}', [Admin\OrderController::class, 'show']);
        Route::post('orders/{order}/validate', [Admin\OrderController::class, 'validatePayment']);
        Route::post('orders/{order}/reject', [Admin\OrderController::class, 'rejectPayment']);
        Route::patch('orders/{order}/status', [Admin\OrderController::class, 'updateStatus']);

        // Khusus owner: laporan keuangan, pengaturan toko, akun pengelola
        Route::middleware('role:owner')->group(function () {
            Route::get('settings', [Admin\SettingsController::class, 'show']);
            Route::match(['put', 'post'], 'settings', [Admin\SettingsController::class, 'update']);

            Route::get('users', [Admin\UserController::class, 'index']);
            Route::post('users', [Admin\UserController::class, 'store']);
            Route::match(['put', 'patch'], 'users/{user}', [Admin\UserController::class, 'update']);
            Route::post('users/{user}/password', [Admin\UserController::class, 'resetPassword']);

            Route::get('reports/export', [Admin\ReportController::class, 'export']);
            Route::get('reports', [Admin\ReportController::class, 'index']);
        });
    });
});
