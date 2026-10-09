# Retail POS — Build Plan

**8 phases.** Each phase has a verification gate. Do not start the next phase until the current gate passes.

## Progress

| Phase | Status |
|---|---|
| 1 — Scaffold & Database | ✅ **Done** — 20 tables, 12 migrations clean, seeded, builds, serves. |
| 2 — Auth, Roles & Session Longevity | ✅ **Done** — 34 tests green, roles enforced, heartbeat live, `/` → login. |
| 3 — Admin CRUD | ✅ **Done** — 47 tests green, all 5 CI checks pass, new theme + motion system. |
| 4 — POS Sync Endpoints | ✅ **Done** — 19 tests: idempotent replay, oversell, per-line tax, proportional discount. |
| 5 — POS UI (online) | ✅ **Done** — real sale driven in a browser; all 5 tables verified. |
| 6 — Offline Layer | ✅ **Done** — 3 offline sales → flushed to exactly 3, twice, in a real browser. |
| 7 — Receipts | ✅ **Done** — prints from Dexie with no network; provisional ref until synced. |
| 8 — Dashboard & Reports | ✅ **Done** — 10 tests, validated chart palette, CSV export. |
| ➕ Public customer menu | ✅ **Done** — `/menu`, no login, 7 tests. Added outside the original 8 phases. |
| ➕ Order History | ✅ **Done** — `/orders`, store-wide, 8 tests. Added outside the original 8 phases. |

### Added — Order History (`/orders`)

Server-side sales history, and **not the same thing** as the receipt drawer inside `/pos`:

| | Receipt drawer (in `/pos`) | Order History (`/orders`) |
|---|---|---|
| Source | that device's IndexedDB | the server |
| Scope | sales rung up on **that device** | every till, every cashier |
| Retention | last 50 synced sales | everything |
| Offline | works | needs a connection |
| Purpose | reprint at the counter | look up any sale |

Searchable by order number, cashier or customer; filterable by status, payment method and date. Ordered by the **business moment**, so a sale synced late sorts to when it happened, not when the row landed. A manager is scoped to their own store — `show` scopes the *lookup*, so another store's order 404s rather than leaking its total. Detail view reprints the receipt through the same print stylesheet the till uses, marked `REPRINT`.

**All 8 phases complete**, plus two additions. 94 tests · 498 assertions. Pint, Prettier, ESLint, vue-tsc and the production build all pass.

### Verified end-to-end in a browser, not just in tests

| Gate | Evidence |
|---|---|
| Phase 5 — a real sale | 3 items rung up online; `orders`, `order_items`, `payments`, `inventory_logs` and `stocks` all correct (6.60 + 0.66 tax = 7.26, change 12.74) |
| Phase 6 — offline queue | Network cut → 3 sales queued in IndexedDB, server still at 0 → reconnect → exactly 3 orders → flushed again → still exactly 3 |
| Phase 8 — reports | 78 seeded sales across 14 days render with correct totals, per-day bars, and payment split |

### Chart palette — validated, not eyeballed

The theme's own `--chart-*` tokens **failed** the categorical validator (gold too light at L 0.77 and under 3:1 contrast; teal below the chroma floor, reading grey). The four `--series-*` slots now come from the dataviz skill's validated palette, re-checked against this project's card surfaces:

- Light and dark both pass lightness, chroma, CVD separation and normal-vision floors.
- Light mode WARNs on contrast for two hues, which obliges **visible labels** — so every payment bar carries its name and amount as text, and a full table sits below the charts.
- Payment colours are pinned per tender type, never by rank, so a change in takings never repaints the bars.

### ⚠️ Concurrent session warning

A second Claude session (`pos-retail-e5`) is editing this repository at the same time. It flattened the category tree — see below — and has been committing to `main`. **Two agents on one working tree will eventually clobber each other.** Before resuming multi-session work, either stop one session or split the work by directory.

### Added — public customer menu (`/menu`)

Browse-only catalogue, the **only unauthenticated route in the app**. No cart, no checkout, no ratings.

- `MenuController` returns a hand-built payload — `id`, `name`, `description`, `image`, `unit`, `category`, `price` — so cost price, SKU, barcode and stock can never leak. A test asserts those keys are absent.
- **Prices are shown tax-inclusive.** Everything else in this build is tax-exclusive, but quoting the net figure on a customer-facing menu would understate what they actually pay at the till.
- Only categories that contain a visible product are listed; inactive products are hidden.
- Filtering is client-side — a menu is a small, fully-loaded list, so a round-trip per keystroke would only make it feel slower.

### Categories are now flat

The `parent_id` self-reference was dropped by the concurrent session (`2026_08_20_140000_drop_parent_id_from_categories_table`). Categories are a single flat list with unique names; the two-level tree was more structure than the POS grid needed. This supersedes the `categories` row in the schema table below.

### Resolved — running on Laravel 12

`composer create-project laravel/vue-starter-kit` installs **Laravel 12.67 + Inertia 2.0.25 + Ziggy 2.6** — Packagist's tags (`v1.0.2`) lag the GitHub repo, where `dev-main` carries Laravel 13 + Inertia 3 + Fortify + Wayfinder.

**Decision: stay on Laravel 12.** It is installed, migrated, seeded and building. Every requirement in this build is version-neutral, and Laravel 12 is supported into 2027. Switching would mean discarding a passing gate for no functional gain.

Two consequences worth recording:
- Auth is the kit's own controllers in `routes/auth.php`, **not Fortify**. Simpler to extend with role checks, and there is no passkeys/2FA table.
- Route helpers are **Ziggy** (`route()` in JS), not Wayfinder.

Table count still lands on **20** — the scaffold contributes `migrations` where the Laravel 13 kit would have contributed `passkeys`.

### Environment notes

- The project lives on an **NTFS** volume (`ntfs3`), and files occasionally get created as `root:root` even though the mount maps to uid 1000. This bit twice: `node_modules/.vite-temp` (build failed with `EACCES`) and `storage/logs/laravel.log` (every test that logged an exception died with "could not be opened in append mode"). **Fix in both cases: delete the file/directory** — the parent is writable, so the app recreates it correctly.
- `/tmp` filled to 0 bytes mid-build and blocked every shell command. If it recurs: `rm -rf /tmp/claude-1000/*`, or relocate with `CLAUDE_CODE_TMPDIR=/media/koeuk/Drive/tmp claude`.
- Tests run against **`pos_retail_test`** on MySQL, not sqlite `:memory:` — `pdo_sqlite` is not installed, and the Phase 4 sync tests want the real engine anyway.

### Laravel 11+ base-controller gotcha

The bare `Controller` class no longer includes `AuthorizesRequests`, and `$this->middleware()` is gone entirely. `authorizeResource()` therefore fails twice over. This build adds the trait back in `app/Http/Controllers/Controller.php` and calls **`$this->authorize()` explicitly** in every action rather than relying on `authorizeResource()`.

### CI

`.github/workflows/lint.yml` runs Pint, Prettier and ESLint; `tests.yml` runs the suite. Before pushing, run all five locally:

```bash
vendor/bin/pint --test && npm run format:check && npx eslint . && npx vue-tsc --noEmit && npm run build
```

`eslint . --fix` cannot auto-fix unused imports, so it exits non-zero and fails the job — that is what broke the first push.

### Phase 1 verified results

| Check | Result |
|---|---|
| Tables in `pos_retail` | **20** |
| Migrations run | 15 (3 baseline + 12 domain) |
| Seed | 1 store · 1 register · 3 users · 11 categories · 22 products · 22 stock rows · 5 settings |
| `npm run build` | ✅ built in 15.47s |
| `/` · `/login` · `/dashboard` | 200 · 200 · 302 (correctly gated) |

Login credentials — all password `password`: `admin@pos.test` · `manager@pos.test` · `cashier@pos.test`

**Architecture in one line:** Inertia SPA, no separate API. One route file, one auth, one middleware stack — the POS sync endpoints are ordinary `web.php` routes that happen to return JSON.

---

## Tech Stack (verified current as of 2026-08-20)

- **Base:** `laravel/vue-starter-kit` — Laravel 13, Inertia 3, Vue 3.5, Tailwind 4, Vite 8, TypeScript
- **UI:** shadcn-vue (drops onto the kit's existing `reka-ui` + `class-variance-authority` + `clsx` + `tailwind-merge`)
- **Auth:** Fortify session auth only. **No Sanctum.**
- **State:** Pinia · **Offline:** Dexie.js — POS checkout screen only · **HTTP:** axios (not bundled with the kit; add it)
- **DB:** MySQL 8.0
- **Route helpers:** Wayfinder (ships with the kit — typed TS route functions)

Local toolchain confirmed: PHP 8.4.20, Node 22.22.2, npm 10.9.7, MySQL 8.0.46.

`npm i axios dexie pinia` — the only three runtime additions.

---

## Locked Decisions

| Decision | Choice | Why |
|---|---|---|
| **API layer** | **None. JSON from `web.php`** | No `routes/api.php`, no `install:api`, no Sanctum, no token guard. The POS sync endpoints are normal web routes returning `response()->json()`, sharing the session cookie and CSRF with every other page. |
| **Session longevity** | **12h lifetime + remember-me** | This replaces what Sanctum device tokens were protecting against. A queued order must still flush after a long shift. See Phase 2. |
| Tax | Exclusive, per-line | `sell_price` excludes tax. Each line taxed at its own `product.tax_rate` (null → 0), summed into `orders.tax_amount`. Supports mixed-rate baskets. |
| Oversell on sync | Accept, allow negative | Never reject a completed sale — cash was taken and goods are gone. `stocks.qty` may go negative; always write the `inventory_log`; surface oversold items on the dashboard. |
| Scaffold | Official vue-starter-kit | Collapses most of phases 1–2. Composables are `.ts`, not `.js`. |
| `order_no` | Server-generated at sync | Format `{store}-{register}-{YYMMDD}-{seq}`. Offline receipt prints short `client_uuid` as provisional ref; reprint after sync shows the real number. |
| Discounts | Flat amounts, not % | Line discount applies first, then order discount against subtotal, then tax on the discounted base. |
| Payment totals | `paid_amount` = SUM(`payments.amount`) | `change_amount` = `paid_amount − total`, cash tender only. |

---

## Architecture

Everything is one Inertia SPA. Two halves differ only in how they talk to the server:

- **Admin half** — dashboard, products, categories, customers, reports, settings, users. Standard Inertia pages: `router.visit()`, form helpers, page props. Always require connection.
- **POS half** — `/pos` only. Inertia loads the page once, then it runs as a self-contained offline-capable screen. **Inside `/pos`, never use Inertia visits or `router`.** Plain `axios` against the `/pos/data/*` web routes, with Dexie as local cache and queue.
- When offline, disable/hide all nav links so Inertia cannot navigate away from `/pos` and strand the cashier.

### Why the POS endpoints return JSON instead of Inertia responses

An Inertia response is a page — props plus a component name — and a POST returns a redirect. Neither can be queued in Dexie and replayed hours later. The offline queue needs endpoints that accept a payload and return a plain data result, idempotently. Those are ordinary `web.php` routes; they simply return JSON rather than `Inertia::render()`. No second API layer, no second auth system.

**axios config:** set `withXSRFToken: true` and `withCredentials: true`. Axios 1.x will not attach the `X-XSRF-TOKEN` header without it, and every `web.php` route enforces CSRF.

---

## UI & Motion

Modern layout with smooth animation throughout — applied to pages, layout, and cards. Two distinct motion budgets, because the admin side and the POS side have opposite needs.

### Admin pages — expressive

- **Page transitions:** fade + 8px rise on Inertia navigation, keyed on the page component so it re-runs per route
- **Cards:** staggered entrance (~40ms apart), settling into place rather than popping
- **Lists & tables:** row fade-in on load; `<TransitionGroup>` for insert/remove so deleting a row slides the rest up instead of snapping
- **Hover/press:** subtle lift on cards, scale-down press feedback on buttons
- **Modals/sheets:** scale from 0.96 + backdrop fade
- **Skeletons** rather than spinners for loading states

### POS checkout — restrained, deliberately

Heavy animation on a touch POS actively hurts. A cashier tapping 60 items a minute needs the UI to feel *instant*, not choreographed.

- Tap feedback under **100ms**, never longer
- Product grid tiles: press-scale only, no entrance animation on re-render
- Cart line insert: fast 120ms slide, no stagger
- No page transitions at all inside `/pos` — it never navigates
- The only expressive motion allowed: the sync status badge and payment-success confirmation

### Non-negotiable rules

- **Animate only `transform` and `opacity`.** Never `width`, `height`, `top`, `left`, or `margin` — those trigger layout on every frame and will visibly stutter on a tablet.
- **Respect `prefers-reduced-motion`** — a global media query that reduces all durations to near-zero. Accessibility requirement, not optional.
- **No animation on the critical sale path.** Nothing between "tap Complete Sale" and the order landing in Dexie may be gated behind a transition finishing.
- Avoid animating `filter: blur()` on large surfaces — it is expensive to composite.

---

## Database — 20 tables

**11 new tables** I write migrations for, plus **9** that arrive with the scaffold.

### `users` — extended, not created

**Laravel already creates this table.** It is not in the numbered list below because I write an *alter* migration for it, not a create. Final shape after all three sources:

| Source | Columns |
|---|---|
| Laravel default (`0001_01_01_000000_create_users_table`) | id, name, email (unique), email_verified_at (nullable), password, remember_token, timestamps |
| Starter kit 2FA migration | two_factor_secret (nullable text), two_factor_recovery_codes (nullable text), two_factor_confirmed_at (nullable ts) |
| **My alter migration** | **role** (enum: admin/manager/cashier), **store_id** (nullable FK → stores, but **required when role = cashier**), **is_active** (bool, default true) |

13 columns total. `users` is referenced by `orders.cashier_id` and `inventory_logs.created_by`.

### New domain tables

| # | Table | Columns |
|---|---|---|
| 1 | `stores` | id, name, address, phone, timestamps |
| 2 | `registers` | id, store_id FK, name, is_active, timestamps |
| 3 | `categories` | id, name, parent_id (nullable FK → categories), timestamps |
| 4 | `products` | id, category_id FK, name, sku (unique), barcode (unique, nullable), description (nullable), cost_price (12,2), sell_price (12,2), tax_rate (5,2, nullable), image (nullable), unit (default 'pcs'), track_stock (bool, default true), is_active, timestamps |
| 5 | `stocks` | id, product_id FK, store_id FK, qty (int), low_stock_threshold (int, nullable), timestamps — **UNIQUE (product_id, store_id)** |
| 6 | `customers` | id, name, phone (nullable), email (nullable), loyalty_points (int, default 0), timestamps |
| 7 | `orders` | id, client_uuid (string, **unique**), order_no (string, unique), store_id FK, register_id (nullable FK), cashier_id FK → users, customer_id (nullable FK), subtotal, discount_amount (default 0), tax_amount (default 0), total, paid_amount, change_amount (all 12,2), status (enum: completed/refunded/void), synced_at (nullable ts), created_offline_at (nullable ts), timestamps |
| 8 | `order_items` | id, order_id FK, product_id FK, product_variant_id (nullable, **no FK**), product_name (snapshot), qty (int), unit_price (12,2 snapshot), discount (12,2, default 0), subtotal (12,2), timestamps |
| 9 | `payments` | id, order_id FK, method (enum: cash/card/qr/credit), amount (12,2), reference_no (nullable), timestamps |
| 10 | `inventory_logs` | id, product_id FK, store_id FK, type (enum: sale/restock/adjustment/return), qty_change (int), reference_type (nullable string), reference_id (nullable bigint), note (nullable text), created_by FK → users, timestamps |
| 11 | `settings` | id, key (unique), value (text), timestamps — receipt header/footer, currency symbol, default tax rate |

Plus the `users` alter migration described above. That is **12 migrations** in Phase 1: 11 creates + 1 alter.

### Schema corrections applied

- `order_items.product_variant_id` — kept as a nullable `unsignedBigInteger` with **no foreign key**, since `product_variants` is deferred to v2. As originally written the constraint could not be created.
- `stocks` — added composite unique on `(product_id, store_id)`. Without it, duplicate stock rows appear and decrement logic silently splits across them.
- `users.store_id` — nullable in general, but **required for the cashier role**. A cashier with no store cannot resolve which stock rows to read, so `/pos` breaks on open.
- `settings` — added. The original architecture listed a settings page with no table behind it.

### Scaffold tables (automatic)

`users` · `password_reset_tokens` · `sessions` · `cache` · `cache_locks` · `jobs` · `job_batches` · `failed_jobs` · `passkeys`

> `personal_access_tokens` is **gone** — it came from Sanctum, which the no-API decision removes. That is the only change to the table count: 21 → 20.

### Deferred to v2

`product_variants`, `discounts`, `register_sessions` (shift / cash-drawer — required for real X/Z reports and end-of-day reconciliation, but outside v1 scope).

### Invariant

In `order_items`, **always** snapshot `product_name` and `unit_price` at time of sale. Never join back to `products` for historical accuracy — prices change.

---

# Phase 1 — Scaffold & Database

**Goal:** A seeded database and a booting app.

- Install `laravel/vue-starter-kit`; point `.env` at MySQL; `npm i axios dexie pinia`
- **Do not run `php artisan install:api`.** There is no `routes/api.php` in this build.
- Write all 12 migrations (11 creates + the `users` alter)
- Eloquent models with relationships, casts, and PHP enums (`Role`, `OrderStatus`, `PaymentMethod`, `InventoryLogType`)
- Seeder: 1 store, 1 register, 3 users (admin/manager/cashier), category tree, ~20 products with barcodes, stock rows for every product

**Gate:** `php artisan migrate:fresh --seed` runs clean and produces exactly 20 tables. `php artisan serve` + `npm run dev` boots and the login page renders.

---

# Phase 2 — Auth, Roles & Session Longevity

**Goal:** Three roles correctly fenced off, and a session that survives a full shift.

- Fortify session auth is already wired by the kit — confirm it works, then layer authorization on top
- `EnsureRole` middleware; route groups per role
- Policies for products, categories, customers, users, stores
- `is_active = false` blocks login

**Session hardening** — this is what replaces the Sanctum device token, and it is the price of dropping the API layer:

- `SESSION_LIFETIME=720` (12 hours) to cover a full shift
- `SESSION_EXPIRE_ON_CLOSE=false` so a tablet sleeping mid-shift does not lose auth
- Login with **remember-me** for POS users — the remember cookie re-establishes a lapsed session automatically
- `GET /pos/data/heartbeat` → `{ok: true}`, a cheap authenticated endpoint that doubles as a genuine connectivity probe and refreshes the `XSRF-TOKEN` cookie. `navigator.onLine` only reports link-layer state and will happily claim "online" on wifi with no internet, so the sync loop must confirm with this before trusting it.

**Gate:** Each of the three seeded users logs in. A cashier hitting an admin route gets 403. The heartbeat endpoint returns 200 for an authenticated cashier and 401 once logged out.

---

# Phase 3 — Admin CRUD

**Goal:** Every admin screen fully usable.

- Controllers + form requests + policies for **products, categories, customers, users, stores**
- Inertia pages: `Products/{Index,Create,Edit}.vue`, `Categories/Index.vue`, `Customers/Index.vue`, plus Users and Stores
- shadcn-vue Table, Dialog, Input, Button, Badge, Card throughout
- App shell: modern sidebar nav + topbar, responsive, collapsible
- Motion per the **UI & Motion** section — page transitions, staggered card entrance, `<TransitionGroup>` on tables, skeleton loaders
- Product image upload; category tree respects `parent_id`
- Cashier form request enforces non-null `store_id`

**Gate:** Create, read, update, and delete works for each resource. Validation errors render. Role restrictions hold in the UI. Transitions run at 60fps with no layout thrash (verify in DevTools Performance), and collapse correctly under `prefers-reduced-motion`.

---

# Phase 4 — POS Sync Endpoints

**Goal:** A sync endpoint that is safe to retry infinitely. This is the most important phase in the build.

All routes live in **`routes/web.php`**, inside the `web` + `auth` + `role:cashier|manager|admin` group, handled by a `PosDataController` that returns JSON. No `routes/api.php` exists.

| Route | Returns |
|---|---|
| `GET /pos/data/products` | Active products with stock for the caller's store, shaped for local caching |
| `POST /pos/data/orders/sync` | Accepts an **array** of orders; returns per-order result keyed by `client_uuid` |
| `GET /pos/data/orders/{client_uuid}/status` | Sync status for one order |
| `GET /pos/data/heartbeat` | `{ok: true}` — connectivity probe + CSRF refresh |

Sync algorithm, per order, inside a DB transaction:

1. `firstOrCreate` on `client_uuid` — this is what makes retries safe
2. If newly created: create `order_items` and `payments`, generate `order_no`, decrement stock, write `inventory_logs`, stamp `synced_at`
3. If already existed: return success without touching anything

Rules:
- Stock is decremented **server-side only**, at sync time. Client-side stock math is never the source of truth.
- Catch the unique-violation race on `client_uuid` — two tabs can flush the same order concurrently.
- Negative stock is permitted. Never reject a completed sale.
- Return `200` with per-order status rather than failing the whole batch on one bad order.

**Gate:** Feature tests pass, including: posting the same `client_uuid` twice creates exactly **one** order; stock decrements by the right amount exactly once; an `inventory_log` row is written per line item; a batch of 10 offline orders syncs in one call; a concurrent double-flush of the same UUID still yields one order.

---

# Phase 5 — POS UI (online only)

**Goal:** A cashier can complete a real sale, with a live connection.

```
resources/js/Pos/
  composables/
    useCart.ts        # cart state via Pinia
    useBarcode.ts     # keyboard-wedge scanner input handling
  components/
    ProductGrid.vue   # searchable/filterable grid, tap to add
    Cart.vue          # line items, qty adjust, remove
    Checkout.vue      # discount, tax, total calculation
    PaymentModal.vue  # cash/card/qr entry, change calculation
```

- `Pages/Pos/Index.vue` — axios only, **no Inertia router**
- Configure axios once with `withXSRFToken: true` and `withCredentials: true`
- Touch-friendly: large tap targets, numpad-style payment entry (it may run on a tablet)
- Tax computed per line, exclusive; discounts flat; totals per the locked invariants

**Gate:** A complete sale end-to-end while online. Verify `orders`, `order_items`, `payments`, `inventory_logs`, and `stocks` rows are all correct. Barcode scanner adds to cart. No 419 errors.

---

# Phase 6 — Offline Layer

**Goal:** The cashier keeps selling with the network off, and everything reconciles when it returns.

```
resources/js/Pos/
  db/dexie.ts                    # schema: products (cache), pendingOrders (queue)
  composables/useOfflineSync.ts  # connectivity detection, sync loop, retry, auth recovery
  components/SyncStatusBadge.vue # online/offline + pending count
```

- **On mount:** load product cache from Dexie; if online, refresh from `/pos/data/products` and update Dexie
- **On "Complete Sale":** generate `crypto.randomUUID()`, write order + items + payment to Dexie as `pending_sync`, then immediately attempt `axios.post('/pos/data/orders/sync', ...)`
- **If offline or the request fails:** leave it queued, no error state for the cashier
- **Background sync:** `window.addEventListener('online', ...)` **and** a ~15s `setInterval`, both confirm connectivity via heartbeat, then flush all `pending_sync` orders. On success mark `synced` in Dexie (keep the last 50 for receipt reprint)
- **Nav lockout:** hide/disable all Inertia nav links while offline

**Auth recovery** — the one thing session auth needs that a token would not:

- On **419** (CSRF expired): re-hit the heartbeat to refresh `XSRF-TOKEN`, retry the flush once
- On **401** (session gone): keep the queue intact and surface "Session expired — log in to sync" on the badge. The cashier can keep selling; nothing is lost
- **Never** clear a queued order except on confirmed server success. This is the single most important rule in the file — a dropped queue entry is lost money

**Gate:** DevTools offline → complete 3 sales → all show pending → go online → queue flushes automatically → server has exactly 3 orders. Repeat the flush and confirm still exactly 3. Then: expire the session server-side mid-queue, confirm orders survive and flush after re-login.

---

# Phase 7 — Receipts

**Goal:** Printing works with no network at all.

- Receipt view rendered **directly from local Dexie data** — must not require a server round-trip
- Simple browser print view (`window.print()` + print stylesheet)
- Pre-sync receipts show the provisional `client_uuid` short ref; reprint after sync shows the real `order_no`
- Header/footer text pulled from `settings`, cached in Dexie on load

**Gate:** Print a receipt while fully offline. Reprint the same sale after sync and confirm the real `order_no` appears.

---

# Phase 8 — Dashboard & Reports

**Goal:** Owners can see what happened.

- `Dashboard.vue` — today's sales, order count, average basket, low-stock alerts, **oversold items** (the negative-stock reconciliation list from Phase 4)
- `Reports/Index.vue` — sales by day, sales by product, payment-method breakdown, basic charts
- Date-range filter; CSV export

**Gate:** Figures reconcile against raw `orders` totals in the database.

---

## Build Discipline

- Confirm each phase's gate before starting the next.
- **Phase 4 is the risk concentration point** — the idempotency tests there are what make the whole offline story trustworthy. Do not rush past them.
- **Phases 5 and 6 are deliberately split** — get the POS fully working online first, then layer Dexie underneath. Debugging offline sync on top of an unproven UI is much harder.
- **Never use Inertia's `router` inside `/pos`.** One accidental `router.post()` and the offline queue silently stops working, because a redirect response cannot be replayed.
