<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Gate a route to one or more roles: ->middleware('role:admin,manager')
     *
     * Also enforces is_active on every request, not just at login — a user
     * deactivated mid-session is locked out on their next request rather
     * than lingering until the session expires.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! $user->is_active) {
            // A token request has no session to tear down — the 403 alone
            // shuts it out, and the token can be revoked from the server.
            if ($request->hasSession()) {
                auth()->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            abort(403, 'This account has been deactivated.');
        }

        if ($roles !== [] && ! in_array($user->role->value, $roles, true)) {
            abort(403, 'You do not have access to this area.');
        }

        return $next($request);
    }
}
