<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * Gate a route to a feature permission: ->middleware('permission:reports')
     *
     * Runs inside the 'role' middleware group, so is_active is already
     * enforced by the time this fires.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $key = Permission::tryFrom($permission);

        if ($key === null) {
            abort(500, "Unknown permission [{$permission}].");
        }

        if (! $user->hasPermission($key)) {
            abort(403, 'You do not have access to this area.');
        }

        return $next($request);
    }
}
