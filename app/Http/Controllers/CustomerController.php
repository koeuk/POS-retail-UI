<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerRequest;
use App\Models\Customer;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Customer::class);

        return Inertia::render('Customers/Index', [
            'customers' => QueryBuilder::for(Customer::class)
                ->withCount('orders')
                ->withSum('orders as spent_total', 'total')
                ->allowedFilters(...[
                    AllowedFilter::callback('search', function (Builder $query, string $search) {
                        $query->where(function (Builder $q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                    }),
                ])
                ->orderBy('name')
                ->paginate(PerPage::resolve($request))
                ->withQueryString(),
            'filters' => ['search' => (string) $request->input('filter.search', '')],
        ]);
    }

    public function store(CustomerRequest $request): RedirectResponse
    {
        try {
            $this->authorize('create', Customer::class);

            DB::transaction(fn () => Customer::create($request->validated()));

            return back()->with('success', 'Customer added.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The customer could not be saved. Nothing was changed — try again.');
        }
    }

    public function update(CustomerRequest $request, Customer $customer): RedirectResponse
    {
        try {
            $this->authorize('update', $customer);

            DB::transaction(fn () => $customer->update($request->validated()));

            return back()->with('success', 'Customer updated.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The customer could not be saved. Nothing was changed — try again.');
        }
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        try {
            $this->authorize('delete', $customer);

            if ($customer->orders()->exists()) {
                return back()->withErrors([
                    'customer' => 'This customer has order history and cannot be deleted.',
                ]);
            }

            DB::transaction(fn () => $customer->delete());

            return back()->with('success', 'Customer deleted.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The customer could not be deleted. Nothing was changed — try again.');
        }
    }
}
