<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\Setting;
use App\Support\Currency;
use App\Support\PerPage;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            // The page-size whitelist, so no client file keeps its own copy.
            'pagination' => ['options' => PerPage::OPTIONS, 'default' => PerPage::DEFAULT],
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user()?->only([
                    'id', 'name', 'email', 'role', 'store_id', 'vendor_id', 'is_active',
                ]),
                // Which shop this person is standing in — shown in the sidebar
                // so a multi-store operator always knows where they are.
                'store_name' => $request->user()?->store?->name,
                // And, for a supplier's own login, which supplier they speak for.
                'vendor_name' => $request->user()?->vendor?->name,
                'can' => [
                    'accessAdmin' => (bool) $request->user()?->role?->canAccessAdmin(),
                    'manage' => (bool) $request->user()?->hasRole(
                        Role::Admin,
                        Role::Manager,
                    ),
                    'isAdmin' => (bool) $request->user()?->isAdmin(),
                    // One flag per feature area, already resolved through the
                    // user's role defaults and per-user overrides. The nav
                    // renders from these; the permission middleware enforces.
                    ...($request->user()?->permissionFlags() ?? []),
                ],
                /*
                 * What may be done inside each area, as
                 * `{products: {view: true, delete: false}}`. Buttons render
                 * from this; the policies and controllers enforce it. Kept
                 * apart from `can` so the nav's flat flags stay flat.
                 */
                'actions' => $request->user()?->actionMatrix() ?? (object) [],
            ],
            // Every price on every page formats through this. Changing the
            // setting therefore changes the whole app on the next request.
            'currency' => fn () => Currency::current()->toArray(),
            // The shop's face: sidebar logo and browser-tab icon. Shared here
            // because the sidebar renders on every authenticated page.
            'branding' => fn () => [
                'logo' => Setting::get('shop_logo'),
                'favicon' => Setting::get('shop_favicon'),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ]);
    }
}
