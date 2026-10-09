<?php

namespace App\Http\Controllers;

use App\Enums\Action;
use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Requests\UserRequest;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $actor = $request->user();
        $isVendor = $actor->hasRole(Role::Vendor);

        return Inertia::render('Users/Index', [
            'users' => QueryBuilder::for(User::class)
                ->with('store:id,name', 'vendor:id,name')
                // A vendor sees its own team: itself and the cashiers it hired.
                ->when($isVendor, fn ($q) => $q->where(fn (Builder $q) => $q
                    ->where('users.id', $actor->id)
                    ->orWhere(fn (Builder $q) => $q
                        ->where('role', Role::Cashier->value)
                        ->where('vendor_id', $actor->vendor_id))))
                ->allowedFilters(...[
                    AllowedFilter::callback('search', function (Builder $query, string $search) {
                        $query->where(function (Builder $q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                    }),
                    AllowedFilter::exact('role'),
                ])
                ->orderBy('name')
                ->paginate(PerPage::resolve($request))
                ->withQueryString()
                ->through(fn (User $u) => array_merge($u->toArray(), [
                    'effective_permissions' => $u->effectivePermissions(),
                ])),
            'stores' => Store::orderBy('name')->get(['id', 'name']),
            'vendors' => Vendor::orderBy('name')->get(['id', 'name']),
            // A vendor may only hire cashiers, so that is all it is offered.
            'roles' => collect($isVendor ? [Role::Cashier] : Role::cases())->map(fn (Role $r) => [
                'value' => $r->value,
                'label' => $r->label(),
            ]),
            'permissionOptions' => collect(Permission::cases())->map(fn (Permission $p) => [
                'value' => $p->value,
                'label' => $p->label(),
                'group' => $p->group(),
                'defaults' => collect(Role::cases())
                    ->mapWithKeys(fn (Role $r) => [$r->value => $p->defaultFor($r)]),
                // Per action as well: a role may reach an area yet not every
                // verb in it (a vendor cannot delete by default).
                'actionDefaults' => collect(Role::cases())
                    ->mapWithKeys(fn (Role $r) => [$r->value => collect(Action::cases())
                        ->mapWithKeys(fn (Action $a) => [$a->value => $p->defaultActionFor($r, $a)])]),
            ]),
            // The columns of the permissions grid — view / add / edit / delete.
            'actionOptions' => collect(Action::cases())->map(fn (Action $a) => [
                'value' => $a->value,
                'label' => $a->label(),
            ]),
            'filters' => ['search' => (string) $request->input('filter.search', ''), 'role' => (string) $request->input('filter.role', '')],
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        try {
            $this->authorize('create', User::class);

            $data = $request->validated();
            $data['password'] = Hash::make($data['password']);
            $data['email_verified_at'] = now(); // staff accounts are created by an admin

            // Only an admin hands out permission overrides — a delegated
            // staff manager creates accounts with role defaults only.
            if (! $request->user()->isAdmin()) {
                unset($data['permissions']);
            }

            // A vendor's hires join the vendor's own team, whatever was sent.
            if ($request->user()->hasRole(Role::Vendor)) {
                $data['vendor_id'] = $request->user()->vendor_id;
            }

            DB::transaction(fn () => User::create($data));

            return back()->with('success', 'Staff account created.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The account could not be saved. Nothing was changed — try again.');
        }
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        try {
            $this->authorize('update', $user);

            $data = $request->validated();

            if (! empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }

            // Only an admin may change what a user is allowed to do — anyone
            // else editing an account (or themselves) leaves overrides as
            // they are. Closes the self-escalation door.
            if (! $request->user()->isAdmin()) {
                unset($data['permissions']);
            }

            // An admin must not be able to lock themselves out of their own
            // account mid-session by demoting or deactivating it.
            if ($user->id === $request->user()->id) {
                $data['role'] = $user->role->value;
                $data['vendor_id'] = $user->vendor_id;
                $data['is_active'] = true;
            } elseif ($request->user()->hasRole(Role::Vendor)) {
                $data['vendor_id'] = $request->user()->vendor_id;
            }

            DB::transaction(fn () => $user->update($data));

            return back()->with('success', 'Staff account updated.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The account could not be saved. Nothing was changed — try again.');
        }
    }

    /**
     * Save one account's permission overrides and nothing else.
     *
     * Separate from update() so the Permissions dialog cannot carry a stale
     * name, email or role along with the switches. The guards are the same
     * ones update() applies: only an admin may hand out access, keys are
     * whitelisted against the enum, and an admin's own row is left alone
     * because admins hold everything regardless.
     */
    public function permissions(Request $request, User $user): RedirectResponse
    {
        try {
            $this->authorize('update', $user);

            abort_unless($request->user()->isAdmin(), 403);

            if ($user->role === Role::Admin) {
                return back()->withErrors([
                    'permissions' => 'Administrators always hold every permission.',
                ]);
            }

            $validated = $request->validate([
                'permissions' => ['required', 'array'],
                'permissions.*' => ['array'],
                'permissions.*.*' => ['boolean'],
            ]);

            // Unknown area keys and unknown action keys are both dropped: a
            // renamed enum case must not leave orphans rotting in the column.
            $permissions = collect($validated['permissions'])
                ->only(Permission::values())
                ->map(fn ($actions) => collect($actions)
                    ->only(Action::values())
                    ->map(fn ($granted) => (bool) $granted)
                    ->all())
                ->all();

            DB::transaction(fn () => $user->update(['permissions' => $permissions]));

            return back()->with('success', "Permissions updated for {$user->name}.");
        } catch (QueryException $e) {
            return $this->failed($e, 'The permissions could not be saved. Nothing was changed — try again.');
        }
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        try {
            $this->authorize('delete', $user);

            if ($user->id === $request->user()->id) {
                return back()->withErrors(['user' => 'You cannot delete your own account.']);
            }

            if ($user->orders()->exists()) {
                DB::transaction(fn () => $user->update(['is_active' => false]));

                return back()->with('success', "{$user->name} has sales history, so the account was deactivated instead of deleted.");
            }

            $name = $user->name;
            DB::transaction(fn () => $user->delete());

            return back()->with('success', "{$name} was deleted.");
        } catch (QueryException $e) {
            return $this->failed($e, 'The account could not be removed. Nothing was changed — try again.');
        }
    }
}
