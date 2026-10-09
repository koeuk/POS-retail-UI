<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Category::class);

        return Inertia::render('Categories/Index', [
            'categories' => QueryBuilder::for(Category::class)
                ->withCount('products')
                ->allowedFilters(...[
                    AllowedFilter::callback('search', fn (Builder $q, string $search) => $q->where('name', 'like', "%{$search}%")),
                ])
                ->orderBy('name')
                ->get(),
            'filters' => ['search' => (string) $request->input('filter.search', '')],
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        try {
            $this->authorize('create', Category::class);

            DB::transaction(fn () => Category::create($request->validated()));

            return back()->with('success', 'Category created.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The category could not be saved. Nothing was changed — try again.');
        }
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        try {
            $this->authorize('update', $category);

            DB::transaction(fn () => $category->update($request->validated()));

            return back()->with('success', 'Category updated.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The category could not be saved. Nothing was changed — try again.');
        }
    }

    public function destroy(Category $category): RedirectResponse
    {
        try {
            $this->authorize('delete', $category);

            if ($category->products()->exists()) {
                return back()->withErrors([
                    'category' => 'This category still has products. Move them first.',
                ]);
            }

            DB::transaction(fn () => $category->delete());

            return back()->with('success', 'Category deleted.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The category could not be deleted. Nothing was changed — try again.');
        }
    }
}
