# Roles & Permissions

How access control works in POS Retail, the patterns every new feature must follow, and the mistakes that reintroduce the bugs this design exists to prevent.

**This doc is the contract.** When writing any feature that touches access control, follow the worked example and the avoid-list below exactly — they contain the actual code to write. If code in the repo disagrees with this doc, one of them is a bug.

## Where everything lives

| Concern                                         | File                                                                           |
| ----------------------------------------------- | ------------------------------------------------------------------------------ |
| Permission keys, labels, groups, role defaults  | `app/Enums/Permission.php`                                                     |
| Roles + store binding rules                     | `app/Enums/Role.php`                                                           |
| Resolution logic (`hasPermission`)              | `app/Models/User.php`                                                          |
| Feature gate middleware (`permission:<key>`)    | `app/Http/Middleware/EnsurePermission.php`                                     |
| `is_active` + role gate (`role`, `role:admin`)  | `app/Http/Middleware/EnsureRole.php`                                           |
| Middleware aliases                              | `bootstrap/app.php`                                                            |
| Route gates                                     | `routes/web.php`                                                               |
| Per-model action rules                          | `app/Policies/*.php`                                                           |
| Shared `auth.can` map for the frontend          | `app/Http/Middleware/HandleInertiaRequests.php`                                |
| Nav visibility (`requires:` keys)               | `resources/js/lib/navigation.ts`                                               |
| Staff dialog (grant/deny UI)                    | `resources/js/pages/Users/Index.vue`                                           |
| Save-path guards (validation, escalation walls) | `app/Http/Requests/UserRequest.php`, `app/Http/Controllers/UserController.php` |
| Overrides storage                               | `users.permissions` JSON column (migration `2026_09_01_100001`)                |

## Function reference

```php
// The only way to ask "can this user use feature X?" — server side.
$user->hasPermission(Permission::Reports): bool

// And the next question down: inside an area they can reach, may they take
// THIS action? Route middleware still gates the area; this gates the verb.
// NOT called can() — that is Laravel's own Authorizable method.
$user->mayDo(Permission::Products, Action::Delete): bool

// Every key resolved for one user, as ['reports' => true, ...].
// Used to feed the Staff dialog and auth.can. Rarely needed elsewhere.
$user->effectivePermissions(): array

// A key's baseline for a role — what the switch shows before overrides.
Permission::Reports->defaultFor(Role::Cashier): bool

Permission::Reports->label();   // 'Reports' — shown in the Staff dialog
Permission::Reports->group();   // 'Selling' — dialog section header
Permission::values();           // ['pos', 'orders', ...] — whitelisting input

// Role questions that are genuinely about the role, not a feature:
$user->isAdmin();  $user->isManager();  $user->hasRole(Role::Cashier);
$role->requiresStore();   // cashiers must be bound to a store
```

```ts
// Frontend — booleans already resolved by the server, never re-derive:
const page = usePage<SharedData>();
page.props.auth.can.reports; // feature flags, one per Permission key
page.props.auth.can.isAdmin; // role flags still exist for role questions
```

## The model in one paragraph

Every user has exactly one **role** — `admin`, `manager`, `cashier`, or `vendor` ([app/Enums/Role.php](../app/Enums/Role.php)). A role is only a _baseline_: it decides what a user can do **by default**. The real unit of access is the **permission** — one key per feature area ([app/Enums/Permission.php](../app/Enums/Permission.php)) — and any individual user can be granted or denied any permission on the Staff screen, regardless of role. Admins bypass the whole table: an admin always holds every permission, so the shop can never lock itself out of its own back office.

## Roles

| Role      | Store binding                             | Default access                                                                                                                                                |
| --------- | ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `admin`   | none (sees all stores)                    | Everything, always. Overrides are ignored.                                                                                                                    |
| `manager` | optional                                  | Everything except **Staff**                                                                                                                                   |
| `cashier` | **required** — `/pos` reads stock from it | **Point of Sale** only                                                                                                                                        |
| `vendor`  | optional; **`vendor_id` required**        | Everything except **Activity** and **Vendors**; **no Delete** in any area. On **Staff** it may only create cashiers, and only sees/edits its own (see below). |

A `vendor` account belongs to one supplier (`users.vendor_id` → `vendors`). A cashier may optionally carry a `vendor_id` too — that puts it on the vendor's team.

## Permissions

Defined in `App\Enums\Permission`, one case per feature area:

| Key | Screen | Admin | Manager | Cashier |
| --- | ------ | :---: | :-----: | :-----: |

The `vendor` role matches Manager's column, except it **has** `users` (for hiring cashiers) and **lacks** `vendors`.

| `pos` | Point of Sale | ✓ | ✓ | ✓ |
| `orders` | Order History | ✓ | ✓ | — |
| `debts` | In Debt | ✓ | ✓ | — |
| `consumption` | Myself | ✓ | ✓ | — |
| `reports` | Reports | ✓ | ✓ | — |
| `products` | Products | ✓ | ✓ | — |
| `categories` | Categories | ✓ | ✓ | — |
| `inventory` | Inventory | ✓ | ✓ | — |
| `customers` | Customers | ✓ | ✓ | — |
| `users` | Staff | ✓ | — | — |
| `stores` | Stores & registers | ✓ | ✓ | — |
| `activity` | Activity log | ✓ | — | — |
| `vendors` | Vendors (+summary) | ✓ | — | — |

Dashboard and the public `/menu` need no permission. Shop settings (`/settings/shop`) are deliberately **role-gated to admin**, not permission-gated — they change what every screen shows.

## How a check resolves

`User::hasPermission(Permission $p)` answers the **area** question, in this order:

1. **Admin?** → always `true`.
2. **Override present** in the `users.permissions` JSON column? → use it.
3. Otherwise → the role's default from `Permission::defaultFor(Role $r)`.

`User::mayDo(Permission $p, Action $a)` answers the **action** question: the area gate first (someone who cannot open Products cannot delete one), then the stored override for that key — or, with no override, `Permission::defaultActionFor(Role, Action)`. That is where a role-level verb rule lives: it follows `defaultFor()` except that `vendor` gets `delete = false` everywhere. The Staff dialog seeds its switches from the same function (`actionDefaults`), so an admin can grant Delete to one vendor account.

An override is stored in one of two shapes, and both are valid:

```jsonc
{"reports": true}                                   // the whole area, every action
{"products": {"view": true, "update": true, "delete": false}}  // per action
```

A plain `true`/`false` means every action follows it — which is why **the action matrix needed no data migration**, and why old overrides keep working untouched. In a per-action map, an action that is _omitted_ falls back to the area's answer, so a partial map is safe. An area whose actions are _all_ false is the same as no access at all, and `hasPermission()` reports it as such.

`NULL` in the column means "no overrides" — the account behaves exactly as its role.

## The three enforcement layers

Access is enforced in three places, and **all three must agree**. The UI layer is convenience; the server layers are the security.

1. **Route middleware** — `->middleware('permission:reports')` ([EnsurePermission](../app/Http/Middleware/EnsurePermission.php)) blocks the request with 403 before the controller runs. Every feature route group in [routes/web.php](../routes/web.php) carries one.
2. **Policies** — decide _what kind_ of action inside an area (`create`/`update`/`delete` vs read). They call `$user->mayDo(Permission::X, Action::Y)` — never `isManager()`, and no longer bare `hasPermission()` for a write. Every policy keeps the `before()` admin bypass. Areas with no model of their own (Inventory movements, debt settlements) carry the same `mayDo()` check as an `abort_unless` in the controller.
3. **Navigation** — the sidebar and phone tab bar render from `auth.can` (shared in [HandleInertiaRequests](../app/Http/Middleware/HandleInertiaRequests.php)); each nav item names its key via `requires:` in [navigation.ts](../resources/js/lib/navigation.ts). A user never sees a door they cannot open.

## Code patterns used

The implementation leans on six patterns — knowing their names makes the code easy to navigate:

1. **Enum as single source of truth** (configuration-in-code). `App\Enums\Permission` is the _only_ place keys, labels, groups, and role defaults exist. The middleware validates against it, the controller builds the Staff dialog from it, the request drops unknown keys against it. Adding a case updates every consumer at once — nothing to keep in sync.

2. **Defaults + sparse overrides**. The role provides the baseline; `users.permissions` stores only deviations, and `NULL` means "inherit everything". This is the null-object flavor of the _strategy_ pattern: an untouched account costs nothing and follows its role automatically when the role changes.

3. **Chain of responsibility at the HTTP edge**. `EnsureRole` (authentication + `is_active`) → `EnsurePermission` (feature gate) → controller. Each middleware handles one concern and passes the request on; a 403 fires before any controller code runs.

4. **Policy objects with a `before()` bypass** (Laravel's authorization pattern). Route middleware answers _"may they enter this area?"_; policies answer _"may they do this specific action to this specific model?"_. The admin short-circuit lives in `before()` so every ability inherits it — never repeated inside individual methods.

5. **Single resolution method** (facade over the decision). All access questions funnel through `User::hasPermission()`. There is exactly one place the admin-bypass → override → default order is written, so the answer cannot differ between the middleware, a policy, and the nav.

6. **Server-resolved view model**. The frontend never re-derives access. `HandleInertiaRequests` shares `auth.can` as already-resolved booleans, and the nav just reads `can[item.requires]`. The client cannot get the logic wrong because the client has no logic.

Supporting boundary patterns: **FormRequest normalization** (`prepareForValidation` in `UserRequest` coerces booleans, whitelists keys, and nulls overrides for admins before rules run) and **guarded mass-assignment** (`permissions` is fillable but stripped in the controller unless the actor is an admin).

## Worked example: adding a new feature area

Suppose we add an **Expenses** screen. Four files, in this order — copy this shape exactly:

**1. `app/Enums/Permission.php`** — one case, three match arms:

```php
case Expenses = 'expenses';

// in label():
self::Expenses => 'Expenses',

// in group() — pick the existing section it belongs to:
self::Pos, self::Orders, self::Debts, self::Consumption, self::Reports,
self::Expenses => 'Selling',

// defaultFor() usually needs no edit: Admin => true and
// Manager => $this !== self::Users already cover a normal feature.
// Only add a condition if cashiers should have it by default.
```

**2. `routes/web.php`** — inside the authenticated group, gate every route of the feature:

```php
Route::middleware('permission:expenses')->group(function () {
    Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
});
```

**3. `app/Policies/ExpensePolicy.php`** — only if the feature has its own model; this is the whole shape:

```php
class ExpensePolicy
{
    /** Admins bypass every check below. */
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::Expenses);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::Expenses);
    }
    // update()/delete() the same way — tighter rules go here, never new enum cases.
}
```

**4. `resources/js/lib/navigation.ts`** — one line in the right group:

```ts
{ title: 'Expenses', href: '/expenses', icon: Wallet, requires: 'expenses' },
```

**Nothing else.** Do **not** touch the Staff dialog, `HandleInertiaRequests`, `UserRequest`, or the migration — they all iterate `Permission::cases()` and pick the new key up automatically. If you find yourself editing them for a new feature, you are off the pattern.

## What to avoid

- **Never gate a feature by role.** Role checks silently ignore per-user overrides — a granted cashier stays blocked and a revoked manager stays allowed. Role checks are only for questions that are _really_ about the role (admin-only shop settings, the cashier store requirement).

    ```php
    // ❌ wrong — override-blind
    Route::middleware('role:admin,manager')->group(...);
    public function update(User $user): bool { return $user->isManager(); }

    // ✅ right
    Route::middleware('permission:products')->group(...);
    public function update(User $user): bool { return $user->hasPermission(Permission::Products); }
    ```

- **Never enforce only in the UI.** Hiding a sidebar item or a button without the route middleware and policy behind it isn't access control — anyone can type the URL. Add the server gate first, the UI hint second.

    ```ts
    // ❌ wrong — v-if is the only wall
    <Button v-if="page.props.auth.can.products" @click="destroy" />
    // ✅ right — the same button, but the route also carries
    //    permission:products and the policy checks delete()
    ```

- **Never let non-admins touch the permission machinery.** Only admins may edit `permissions` or create admin accounts; both are enforced server-side ([UserRequest](../app/Http/Requests/UserRequest.php), [UserController](../app/Http/Controllers/UserController.php)). Don't "simplify" this — it is the wall against self-escalation through a colleague's account.
- **Never write the `permissions` column by hand.** Go through the Staff dialog or `User::update(['permissions' => ...])` with keys from `Permission::values()`. Unknown keys are dropped on save; hand-written JSON with stale keys just rots.
- **Never special-case the admin outside `before()`/`hasPermission()`.** The admin bypass lives in exactly two places. Scattering `if ($user->isAdmin())` through controllers creates paths that disagree with the policy layer.

    ```php
    // ❌ wrong — a third copy of the bypass that will drift
    if ($user->isAdmin() || $user->hasPermission(Permission::Reports)) { ... }
    // ✅ right — hasPermission() already returns true for admins
    if ($user->hasPermission(Permission::Reports)) { ... }
    ```

- **Don't add a permission for something that isn't a feature area.** The enum is the menu the Staff dialog renders and it must stay short enough to read at a glance. "May edit but not delete" is not a new case — it is an `Action` inside an existing one, resolved by `mayDo()` and enforced in that model's policy.

    ```php
    // ❌ wrong — four cases per screen, and the dialog becomes a wall of text
    case ProductsView = 'products.view';
    case ProductsDelete = 'products.delete';

    // ✅ right — one case per area, the verb is an Action
    $user->mayDo(Permission::Products, Action::Delete);
    ```

- **Don't remove enum cases casually.** Stored overrides referencing a removed key are ignored harmlessly, but renaming a key orphans existing grants — if you must rename, migrate the JSON column.

## The HTTP surface — every route and its gate

Checked against `php artisan route:list` and [routes/web.php](../routes/web.php). Every authenticated request first passes `auth` + `verified` + bare `role` (which enforces `is_active`), then the gate below. **Inertia** routes render pages; **JSON** routes are the app's API.

### No permission required

| Route                                                             | Gate                 | Notes                                                                                                              |
| ----------------------------------------------------------------- | -------------------- | ------------------------------------------------------------------------------------------------------------------ |
| `GET /menu`                                                       | _none — public_      | Read-only customer menu: names, photos, prices. Never stock or staff.                                              |
| `GET /`                                                           | auth redirect        | Guests → login, staff → dashboard.                                                                                 |
| `GET /dashboard`                                                  | signed in            | Figures inside are still scoped (cashiers see their store; the owner row needs `auth.can.accessAdmin`).            |
| `/settings/profile`, `/settings/password`, `/settings/appearance` | signed in            | About the signed-in person only.                                                                                   |
| `GET/PUT /settings/shop`                                          | **role: admin**      | Deliberately role-gated, not permission-gated — see above.                                                         |
| `GET/PUT /settings/payments`, `POST /settings/payments/test`      | **role: admin**      | Same reasoning as shop settings — and the account ID decides where QR money lands. See [payments.md](payments.md). |
| `GET /admin/ping`                                                 | role: admin, manager | Health check for the admin area.                                                                                   |

### Feature areas (Inertia pages + their writes)

Each row is one `Route::middleware('permission:<key>')` group — the middleware 403s before any controller code runs. Policies then refine _which_ action inside the area.

| Permission    | Routes                                                                                                                                                                                                                                                                              |
| ------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `pos`         | `GET /pos` — and the whole POS data API below                                                                                                                                                                                                                                       |
| `orders`      | `GET /orders`, `GET /orders/{order}`                                                                                                                                                                                                                                                |
| `debts`       | `GET /debts` · `POST /debts` (put a sale straight on the book) · `GET /debts/product-lookup` (JSON) · `POST /debts/{order}/settle`                                                                                                                                                  |
| `consumption` | `GET /consumption`                                                                                                                                                                                                                                                                  |
| `reports`     | `GET /reports`, `GET /reports/export` (CSV download)                                                                                                                                                                                                                                |
| `products`    | Full resource: index/create/store/show/edit/update/destroy                                                                                                                                                                                                                          |
| `categories`  | index/store/update/destroy                                                                                                                                                                                                                                                          |
| `inventory`   | `GET /inventory` · `GET /inventory/lookup` (JSON) · `POST /inventory/movements` · `PUT /inventory/threshold`                                                                                                                                                                        |
| `customers`   | index/store/update/destroy                                                                                                                                                                                                                                                          |
| `users`       | index/store/update/destroy (admin-only by default; see invariants)                                                                                                                                                                                                                  |
| `stores`      | `GET/POST /stores`, `PUT/DELETE /stores/{store}`, `POST/PUT .../registers`                                                                                                                                                                                                          |
| `vendors`     | index/show/store/update/destroy — show is the per-vendor summary (products, stock on hand, sales over 7/30/90 days); destroy refuses while the vendor has staff accounts                                                                                                            |
| `activity`    | `GET /activity` (whole log) · `GET /<resource>/{id}/history` (a record's own history page, beside its show/edit endpoints: products, categories, customers, stores, inventory, users) — read-only by design: no write route exists, rows age out via the weekly `activitylog:clean` |

## The POS data API

JSON endpoints under `/pos/data/*`, all behind `permission:pos`. This is a **session API**: the till is the same browser session as the app (cookie + CSRF token), there are no tokens to issue or revoke — deactivating the user (bare `role` middleware) cuts the till off on its next request. The heartbeat re-supplies the CSRF token so a tablet that slept through a session rotation can keep posting.

| Endpoint                                                                                           | Purpose                                                                                                                                                                                                                                                                                                                     |
| -------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /pos/data/heartbeat`                                                                          | Liveness + identity: `{ ok, server_time, user_id, store_id, csrf_token }`.                                                                                                                                                                                                                                                  |
| `GET /pos/data/products`                                                                           | The whole offline bundle in one payload, cached into Dexie: `{ store_id, synced_at, products[], categories[], registers[], settings{ receipt_header, receipt_footer, currency } }`. Product `stock_qty` is a **hint for the cashier, never the source of truth** — for a pack it is whole packs coverable, not loose units. |
| `GET /pos/data/customers?q=`                                                                       | Name/phone search, tiny rows (`id, name, phone`) — for attaching a debt at the till.                                                                                                                                                                                                                                        |
| `POST /pos/data/customers`                                                                         | Create in one tap from the picker: `{ name, phone? }` → `201 { id, name, phone }`. A database failure answers `503 { message }` in the shape the checkout can show.                                                                                                                                                         |
| `POST /pos/data/orders/sync`                                                                       | Flush the offline queue — the contract below.                                                                                                                                                                                                                                                                               |
| `GET /pos/data/orders/{clientUuid}/status`                                                         | `404 { status: 'pending' }` until the order lands, then `{ status: 'synced', order_id, order_no, total, synced_at }`.                                                                                                                                                                                                       |
| `POST /pos/data/qr/charges`, `GET …/{charge}`, `POST …/{charge}/confirm`, `POST …/{charge}/cancel` | Per-sale KHQR: mint, poll, confirm by hand, cancel. A store-bound cashier only sees its own store's charges (404 otherwise). See [payments.md](payments.md).                                                                                                                                                                |

### The sync contract

`POST /pos/data/orders/sync` takes `{ orders: [...] }`, at most **200 per batch** (a tablet offline all day can only hold so much). Each order:

```jsonc
{
    "client_uuid": "…", // generated on the till BEFORE any network — the idempotency key
    "register_id": 1,
    "customer_id": null, // required by validation when sale_type is "debt"
    "sale_type": "customer", // "customer" | "debt" | "myself"; omitted → "customer"
    "created_offline_at": "…", // when it was rung up — this, not arrival time, sets the business day
    "discount_amount": "0.00",
    "items": [{ "product_id": 1, "product_name": "…", "qty": 2, "unit_price": "2500", "discount": "0" }],
    "payments": [{ "method": "cash", "amount": "5000", "reference_no": null }],
}
```

Rules the server holds, in the order they matter:

- **Always 200 with per-order results.** One malformed order must not strand the 49 behind it; each entry in `results[]` reports its own `{ client_uuid, status, order_id, order_no, message }`.
- **Idempotent on `client_uuid`.** A retry of an already-synced order collapses into the original — the retry is what makes offline safe, the collapse is what makes the retry safe.
- **The server owns the numbers.** Totals are recomputed from items server-side; the order number is minted from the shop's code (Settings → Shop) or `S{store}-R{register}`; stock is decided at sync time, never trusted from the till.
- **Cashiers are pinned to their store.** A cashier's payload may _claim_ any `store_id`; the binding wins. Admins/managers may name a store, falling back to the first.
- **Sale types change the money, not the shape.** `customer`: payments recorded as sent. `debt`: payments are the **deposit** — capped at the bill (sync never rejects a completed sale), zero-amount rows skipped, `paid_amount` = deposit, the rest is owed. `myself`: stock moves, no revenue, no payment rows ever.

### Who may call what — the permission is the API key

There is no separate API auth model to keep in sync: the same `permission:pos` gate covers the page _and_ its data. Grant a user `pos` on the Staff screen and their till works; revoke it and every endpoint above starts returning 403 — including a queue flush from a tablet they still have open. That is intentional: the queue survives in Dexie and syncs when an allowed user signs in.

## The token API (`/api/v1/*`)

> Full endpoint reference with request/response examples: **[docs/api.md](api.md)**.

The same app through a second door, for integrations and scripts. Implemented with Laravel Sanctum personal access tokens ([routes/api.php](../routes/api.php), [app/Http/Controllers/Api](../app/Http/Controllers/Api)) — and **the same permission gates answer for a token exactly as for a session**: every endpoint sits behind `permission:<key>`, resolved for the token's user, with the bare `role` middleware enforcing `is_active` on every request.

| Endpoint                                              | Gate            | Notes                                                                                                                           |
| ----------------------------------------------------- | --------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `POST /api/v1/auth/token`                             | throttle 10/min | `{ email, password, device_name }` → `201 { token, user, can }`. Deactivated accounts are refused.                              |
| `DELETE /api/v1/auth/token`                           | signed in       | Revokes the very token that authenticated the request.                                                                          |
| `GET /api/v1/me`                                      | signed in       | The user plus the resolved `can` map — the API twin of `auth.can`.                                                              |
| `GET /api/v1/products`, `GET /api/v1/products/{id}`   | `products`      | Same `filter[search]`, `filter[category_id]`, `filter[status]` grammar as the web.                                              |
| `GET /api/v1/categories`                              | `categories`    |                                                                                                                                 |
| `GET/POST /api/v1/customers`                          | `customers`     |                                                                                                                                 |
| `GET /api/v1/orders`, `GET /api/v1/orders/{id}`       | `orders`        | Store-scoped like the web screen; `filter[from]`/`filter[to]` bucket by business day.                                           |
| `POST /api/v1/orders/sync`                            | `pos`           | **Identical contract** to `/pos/data/orders/sync` — same request rules, same per-order results, same `client_uuid` idempotency. |
| `GET /api/v1/debts`, `POST /api/v1/debts/{id}/settle` | `debts`         | Settling writes an ordinary payment row and recomputes `paid_amount` from the ledger.                                           |
| `GET /api/v1/inventory`                               | `inventory`     | Read-only; movements still go through the web screen or sync.                                                                   |
| `GET /api/v1/reports/summary?from&to`                 | `reports`       | Totals, by-day, by-product, by-payment, outstanding debts — clamped to a year like the web.                                     |

Rules that keep the two doors honest:

- **No second permission model.** Tokens carry no abilities of their own; access is `hasPermission()` at request time, so a Staff-screen grant or revoke applies to existing tokens immediately.
- **Deactivation kills tokens.** `EnsureRole` runs on every API request; it skips the session teardown when there is no session ([EnsureRole](../app/Http/Middleware/EnsureRole.php)) but the 403 stands.
- **Store pinning survives the door.** A cashier's token is bound to their store for orders, debts, inventory and sync, exactly as their session is.
- Lists paginate through the same `PerPage` whitelist (`?per_page=`), and filters use the same Spatie grammar — one query language across web and API.

## Vendor accounts and the Staff screen

A vendor holds `users`, so it can open Staff — but `UserPolicy::inReach()` and `UserRequest` keep it to its own team:

- It lists only itself and cashiers with its `vendor_id` (`UserController::index`).
- It may create **cashiers only** (`role` rule in `UserRequest`); the controller pins the hire's `vendor_id` to the vendor's own, whatever was sent.
- It may update/delete only cashiers on its team (`UserPolicy::inReach`), never other staff.
- Like every non-admin, it cannot edit `permissions`.

## Security invariants (keep these true)

- `EnsureRole` (bare, on the authenticated group) enforces `is_active` on every request — a deactivated user is out on their next click, mid-session.
- You cannot demote, deactivate, or delete **yourself**.
- Non-admins cannot edit or delete **admin accounts**, and cannot mint new admins.
- A user with sales history is deactivated instead of deleted, so order history keeps its cashier.
