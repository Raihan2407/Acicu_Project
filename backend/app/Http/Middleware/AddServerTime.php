<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Menyertakan jam server (epoch milidetik) di setiap respons API agar jam frontend selalu sinkron. */
class AddServerTime
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Server-Time', (string) now()->getTimestampMs());

        return $response;
    }
}
