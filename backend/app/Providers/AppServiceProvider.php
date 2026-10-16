<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Batasi percobaan login admin: 5x per menit per IP + email.
        RateLimiter::for('admin-login', fn (Request $request) => Limit::perMinute(5)
            ->by($request->ip().'|'.strtolower((string) $request->input('email'))));

        // Batasi pembuatan pesanan publik agar tidak di-spam.
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
