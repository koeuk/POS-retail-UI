<?php

namespace App\Listeners;

use App\Models\Activity;
use App\Support\AuditLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Sign-in, sign-out and failed attempts.
 *
 * These ride Laravel's own auth events rather than the login controller, so
 * every entry point is covered at once — the web form, token auth from the
 * API, and the till's session — without each having to remember to log.
 */
class LogAuthenticationActivity
{
    public function handleLogin(Login $event): void
    {
        AuditLog::auth('Signed in', $event->user, [
            'guard' => $event->guard,
        ], 'login');
    }

    public function handleLogout(Logout $event): void
    {
        if (! $event->user) {
            return;
        }

        AuditLog::auth('Signed out', $event->user, [
            'guard' => $event->guard,
        ], 'logout');
    }

    /**
     * A failed attempt has no causer — nobody is authenticated. The email
     * tried is recorded because a run of them against one account is the
     * pattern worth spotting; the password never is.
     */
    public function handleFailed(Failed $event): void
    {
        activity(Activity::LOG_AUTH)
            ->withProperties(['email' => $event->credentials['email'] ?? null])
            ->event('failed')
            ->log('Failed sign-in attempt');
    }
}
