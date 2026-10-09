# POS Retail

An offline-first point-of-sale and back-office for a Cambodian retail shop. Riel-native
pricing, pack sizes (can / six-pack / case sold as one product), customer debt tabs, a
public QR menu, and per-store stock.

Laravel 12 · Inertia · Vue 3 + TypeScript · Tailwind · MySQL

---

## Setup after cloning

### Requirements

| Tool | Version | Notes |
| --- | --- | --- |
| PHP | 8.2+ | with `pdo_mysql` |
| Composer | 2.x | |
| Node | 22 | what CI pins; npm 10+ |
| MySQL | 8.x | or MariaDB |

Check your PHP has the database driver before starting — a missing one is the most
common first-run failure:

```bash
php -m | grep pdo_mysql
```

### Steps

```bash
# 1. Install dependencies
composer install
npm ci

# 2. Environment
cp .env.example .env
php artisan key:generate
```

**3. Point `.env` at your database.** `.env.example` ships with `DB_CONNECTION=sqlite`,
but this project runs on MySQL — replace that single line with a full block:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pos_retail
DB_USERNAME=root
DB_PASSWORD=your_password
```

Create the schema, then let Laravel fill it:

```bash
mysql -u root -p -e "CREATE DATABASE pos_retail;"

# 4. Tables and starting data
php artisan migrate
php artisan db:seed

# 5. Product images need the public disk exposed
php artisan storage:link

# 6. Compile the frontend
npm run build
```

Serve it with `php artisan serve` and sign in at http://127.0.0.1:8000.

### Accounts created by the seeder

| Email | Password | Role |
| --- | --- | --- |
| `admin@gmail.com` | `12345678` | Admin |
| `manager@gmail.com` | `12345678` | Manager |
| `cashier@gmail.com` | `12345678` | Cashier |

The seeder creates one store, one register, and the shop settings — but **no catalogue**,
since products are the shop's own. For a demo shelf to click around:

```bash
php artisan db:seed --class=DemoSeeder
```

### Two things that bite after a clone

- **`/docs` returns 500 until Scribe generates.** The API reference renders from files
  that are gitignored. Run `php artisan scribe:generate` once after cloning, and again
  after changing any annotation on an API controller.
- **`public/build` is gitignored**, so the app serves no CSS or JS until `npm run build`.
  There is usually no vite dev server running, which means **every change to a `.vue` or
  `.ts` file needs another `npm run build`** to appear in the browser.

---

## Day-to-day commands

```bash
php artisan serve        # app on :8000
npm run dev              # vite with hot reload (then assets come from vite, not build/)
composer dev             # server + queue + logs + vite together

./vendor/bin/phpunit     # tests
npm run lint             # eslint --fix
npm run format           # prettier over resources/
```

---

## Project structure

```
app/
├── Enums/           Permission, Role, Action, OrderStatus, PaymentMethod, SaleType…
│                    The vocabulary the whole app agrees on. Adding a feature area
│                    starts with a Permission case here.
├── Http/
│   ├── Controllers/ Web controllers return Inertia pages; Controllers/Api/ returns JSON
│   ├── Middleware/  HandleInertiaRequests shares auth.can with every page
│   └── Requests/    Validation, and the guards on who may mint an admin
├── Models/          Product, Order, Customer, Stock, Store, User, Activity…
│   └── Concerns/    RecordsActivity — the audit trait models opt into
├── Policies/        Per-model authorisation, all routed through hasPermission/mayDo
├── Services/        OrderTotals, OrderSyncService (offline replay), SalesReporter…
├── Observers/       ActivityObserver — model auditing
├── Listeners/       LogAuthenticationActivity — login/logout trail
└── Support/         AuditLog, Currency, PerPage — small shared helpers

resources/js/
├── pages/           One folder per screen (Products/, Orders/, Pos/, Reports/…).
│                    Inertia maps a controller's page name straight to a file here.
├── components/      Shared UI; components/ui/ is the shadcn-vue primitive layer
├── composables/     usePermissions, useCurrency, useTelegram, useIsMobile…
├── layouts/         AppLayout (the shell), auth and settings layouts
└── types/           Shared TypeScript shapes (Product, Order, Paginated<T>…)

routes/              web.php · api.php (token API) · auth.php · settings.php
database/
├── migrations/      Schema history
└── seeders/         DatabaseSeeder (store, users, settings) · DemoSeeder (catalogue)
docs/                roles-and-permissions.md · api.md · api-guide.md
```

---

## Conventions worth knowing before you edit

These are the rules the codebase already follows; breaking them causes subtle bugs
rather than loud errors.

**Access control has one path.** Feature access is always
`$user->hasPermission(Permission::X)` — never a role check like `isManager()`. A new
feature area is four edits: a `Permission` enum case, `permission:<key>` route
middleware, a policy using `hasPermission`, and `requires: '<key>'` on the nav item.
Hiding a button is never the wall; the route middleware and the policy are.
**Read [docs/roles-and-permissions.md](docs/roles-and-permissions.md) before touching
any of it.**

**Stock is per-store.** It lives in the `stocks` table. There is no `stock_qty` column
on products — sum or scope it explicitly.

**Money uses the shop's currency minor factor.** Riel has none, so never assume cents.

**Lists paginate through one path:** `App\Support\PerPage` plus the shared
`Pagination.vue`, with page-size options arriving as shared props.

**The audit trail has two doors.** Model changes go through the `RecordsActivity` trait
with an explicit `$auditable` field list — never `logAll()`, so unlisted columns like
password hashes stay out of the log. Event-shaped entries (money, auth, access) go
through `App\Support\AuditLog`, never a raw `activity()` call in a controller.

**The token API reuses the web permission gates.** Never add API-only auth logic. It is
documented in [docs/api.md](docs/api.md) with an integrator walkthrough in
[docs/api-guide.md](docs/api-guide.md).

---

## Tests

```bash
./vendor/bin/phpunit
```

CI runs the suite on every push to `main` and `develop` (`.github/workflows/tests.yml`),
alongside a lint workflow.
