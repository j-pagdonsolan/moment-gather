<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    /**
     * Handle an incoming request.
     *
     * Only allows users who are both active and have the super_admin role.
     * Aborts with 403 if either condition is not met.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        $user->loadMissing('roles');

        if (! $user->isAdmin() || ! $user->isActive()) {
            abort(403);
        }

        return $next($request);
    }
}
