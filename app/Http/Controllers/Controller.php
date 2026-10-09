<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

/**
 * Laravel 11+ ships a bare base controller — AuthorizesRequests is no longer
 * included by default, so authorize() and authorizeResource() are undefined
 * until the trait is added back here.
 */
abstract class Controller
{
    use AuthorizesRequests;

    /**
     * What every write action does when the database fails mid-save.
     *
     * The exception is reported, never swallowed, and the user lands back on
     * the form with their input and a plain sentence instead of a 500 page
     * nobody at the till can act on. Only QueryException reaches here: the
     * write actions catch that alone, so a bug still surfaces as a bug.
     */
    protected function failed(QueryException $e, string $message): RedirectResponse
    {
        report($e);

        return back()->withInput()->with('error', $message);
    }
}
