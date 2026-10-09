# POS Retail

Laravel 11 + Inertia (Vue 3, TypeScript) + Tailwind. Offline-first POS for a Cambodian retail shop: riel-native pricing, pack sizes (can/six-pack/case as one product), customer debt tabs, a public QR menu, multi-store stock.

## Access control — read the doc first

Before writing ANY code that touches roles, permissions, route gating, policies, or nav visibility, read **[docs/roles-and-permissions.md](docs/roles-and-permissions.md)** and follow it exactly. It contains the file map, the function reference, a copy-paste worked example for adding a feature area, and wrong-vs-right snippets. The short version:

- Feature access goes through `$user->hasPermission(Permission::X)` — **never** `isManager()` / `role:admin,manager` for features.
- Per-action rules (may edit but not delete) go through `$user->mayDo(Permission::X, Action::Y)` in the policy — never as extra `Permission` cases.
- New feature area = 4 edits only: `Permission` enum case → `permission:<key>` route middleware → policy using `hasPermission` (keep the `before()` admin bypass) → `requires: '<key>'` nav item. Everything else picks the key up automatically.
- UI hiding is never the wall — the route middleware and policy are.
- Only admins may edit `permissions` or mint admins; keep the guards in `UserRequest`/`UserController`.

## Conventions

- The token API (`/api/v1/*`) is documented in [docs/api.md](docs/api.md) (reference) and [docs/api-guide.md](docs/api-guide.md) (integrator walkthrough); it reuses the web permission gates — never add API-only auth logic.
- `/docs` (Scribe) renders from generated files that are gitignored — after a fresh clone, or any change to an API controller's annotations, run `php artisan scribe:generate` or the docs routes 500.

- Frontend assets are served from `public/build` — run `npm run build` after JS/Vue changes (no vite dev server is usually running).
- Stock is per-store in the `stocks` table; there is no `stock_qty` column on products. Sum or scope it explicitly.
- Money uses the shop's currency minor factor (riel has none) — never assume cents.
- Lists paginate through `App\Support\PerPage` + the shared `Pagination.vue`; page-size options come from the server via shared props.
- The audit trail uses `spatie/laravel-activitylog` (v5) with a custom `App\Models\Activity`. Model auditing goes through the `RecordsActivity` trait + a `$auditable` field list (never `logAll()` — unlisted columns like password hashes must stay out of the log); event-shaped entries (money, auth, access) go through `App\Support\AuditLog`, never raw `activity()` calls in controllers.
- `demo/` holds rendered demo videos — gitignored, never commit them.
