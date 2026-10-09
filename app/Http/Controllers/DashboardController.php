<?php

namespace App\Http\Controllers;

use App\Services\SalesReporter;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $reporter = SalesReporter::for($user);

        $request->validate(['date' => ['nullable', 'date']]);

        // The shop's day, not the server's — see SalesReporter::businessDay().
        // A ?date= filter shows any past day with the same screen; the trend
        // and the "yesterday" comparison follow the chosen day along.
        $today = $request->filled('date')
            ? \Illuminate\Support\Carbon::parse($request->input('date'))->startOfDay()
            : SalesReporter::businessNow()->startOfDay();
        $yesterday = $today->copy()->subDay();

        try {
            return Inertia::render('Dashboard', [
                'today' => $reporter->summaryFor($today),
                'yesterday' => $reporter->summaryFor($yesterday),

                // Seven days including today, for the sparkline.
                'trend' => $reporter->salesByDay($today->copy()->subDays(6), $today),

                'lowStock' => $reporter->lowStock(),

                // The reconciliation list: stock driven negative by offline sales
                // that synced after the shelf was already empty.
                'oversold' => $reporter->oversold(),

                'recentOrders' => $reporter->recentOrders(),
                'offlineToday' => $reporter->offlineOrdersToday($today),
                'debts' => $reporter->outstandingDebts(),
                'myself' => $reporter->myselfSpent(),

                // How big the shelf is — shown as summary tiles on the phone.
                'catalogue' => [
                    'products' => \App\Models\Product::query()->active()->base()->count(),
                    'categories' => \App\Models\Category::query()->count(),
                ],
                'canSeeReports' => $user->role->canAccessAdmin(),
                'filters' => [
                    'date' => $today->toDateString(),
                    'isToday' => $today->isSameDay(SalesReporter::businessNow()),
                ],
            ]);
        } catch (QueryException $e) {
            // The first screen after login must open. Empty figures and a
            // reason beat a 500 that hides the Point of Sale link too.
            report($e);
            $request->session()->flash('error', 'Today\'s figures could not be loaded. Try again in a moment.');

            return Inertia::render('Dashboard', [
                'today' => SalesReporter::emptyTotals(),
                'yesterday' => SalesReporter::emptyTotals(),
                'trend' => [],
                'lowStock' => [],
                'oversold' => [],
                'recentOrders' => [],
                'offlineToday' => 0,
                'debts' => ['count' => 0, 'owed' => '0.00'],
                'myself' => ['week' => ['count' => 0, 'value' => '0.00'], 'month' => ['count' => 0, 'value' => '0.00'], 'year' => ['count' => 0, 'value' => '0.00']],
                'catalogue' => ['products' => 0, 'categories' => 0],
                'canSeeReports' => $user->role->canAccessAdmin(),
                'filters' => [
                    'date' => $today->toDateString(),
                    'isToday' => $today->isSameDay(SalesReporter::businessNow()),
                ],
            ]);
        }
    }
}
