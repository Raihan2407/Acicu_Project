<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\AddServerTime;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [ForceJsonResponse::class, AddServerTime::class]);

        $middleware->alias([
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Backend ini murni API: semua error dikembalikan sebagai JSON.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
    })->create();
