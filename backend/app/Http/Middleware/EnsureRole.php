<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pembatasan akses berdasarkan peran. Contoh pemakaian di route: ->middleware('role:owner')
 * atau ->middleware('role:owner,admin').
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active || ! in_array($user->role, $roles, true)) {
            abort(403, 'Anda tidak memiliki akses ke fitur ini.');
        }

        return $next($request);
    }
}
