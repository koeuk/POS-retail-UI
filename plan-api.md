# Mobile API — Build Plan

A JSON API for a native mobile app that mirrors the web dashboard. **Phase 1 is read-only: Dashboard + Reports.** Selling, catalogue and orders are sketched at the end so the foundation laid here does not have to be reworked when they arrive.

The API is not a second system. Every endpoint calls the same service the web page calls — `SalesReporter`, `OrderSyncService` — so the phone and the browser can never disagree about a number. The controllers are thin: authenticate, validate, call the service, shape the JSON.

---

## Decisions already made

| Decision | Choice | Why |
|---|---|---|
| Auth | **Sanctum bearer tokens**, one per device | Native HTTP clients handle a header far better than a cookie; a lost phone is revoked on its own without logging everyone out |
| Versioning | URL prefix `/api/v1` | An app in the field cannot be redeployed on the day the server changes; v1 must keep working while v2 is built |
| Money | Decimal **strings**, in the shop's currency, with a `currency` object on every response | Floats drift; the client must never do arithmetic on a float it parsed. The shop's currency is stored, not converted — see `Currency` |
| Dates | ISO-8601 with offset for instants; `YYYY-MM-DD` for business days | The shop's day is `POS_BUSINESS_TIMEZONE`, not UTC — a sale at 06:00 Phnom Penh is the previous UTC day, and the API must group it the way the dashboard does |
| Store scoping | Same rule as the web | Admins see every store and may pass `?store_id=`; everyone else is pinned to their own and the parameter is ignored |
| Errors | Standard Laravel JSON: `{ message, errors? }` with 401 / 403 / 422 | The app already needs to handle these three; nothing bespoke to learn |

---

## Package

```
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate      # personal_access_tokens
```

`User` gains `HasApiTokens`. Token abilities are not used in phase 1 — role checks come from the existing `role` middleware, which already reads `$request->user()` and works identically for a token-authenticated request.

Token expiry: **none by default** (a shop tablet stays signed in), revocable per device. Set `sanctum.expiration` later if policy changes.

---

## Endpoints — phase 1

All under `/api/v1`. All except `auth/login` require `Authorization: Bearer <token>`.

### Auth

| Method | Path | Role | Purpose |
|---|---|---|---|
| POST | `auth/login` | — | Email + password + `device_name` → token |
| POST | `auth/logout` | any | Revoke **this** token |
| POST | `auth/logout-all` | any | Revoke every token for this user (lost phone) |
| GET | `auth/me` | any | Who am I, my role, my store, my abilities |

**Login request**
```json
{ "email": "admin@pos.test", "password": "...", "device_name": "Koeuk's iPhone" }
```

**Login response** `201`
```json
{
  "token": "1|abc...",
  "user": {
    "id": 1, "name": "Admin", "email": "admin@pos.test",
    "role": "admin",
    "store": { "id": 1, "name": "Main Store" } | null,
    "can": { "accessAdmin": true, "manage": true, "isAdmin": true }
  },
  "currency": { "code": "KHR", "symbol": "៛", "decimals": 0 },
  "business_timezone": "Asia/Phnom_Penh"
}
```

Deactivated users get `403` with the same message the web shows. Wrong credentials get `422` on `email`, matching the web login form so the app can reuse its error handling.

`auth/me` returns the `user`, `currency` and `business_timezone` block above — the app calls it on launch to refresh role and currency without re-logging.

### Dashboard

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `dashboard` | any signed-in | Everything the web dashboard shows, in one call |

One endpoint, not six. The dashboard is one screen; making the app assemble it from six requests is six chances to render half a screen.

```json
{
  "day": "2026-08-27",
  "today":     { "sales": "1250000", "orders": 42, "basket": "29761.90", "items": 118 },
  "yesterday": { "sales": "980000",  "orders": 35, "basket": "28000.00", "items": 96 },
  "trend": [ { "day": "2026-08-21", "sales": "...", "orders": 30 }, ... 7 rows ],
  "low_stock": [ { "product": { "id": 3, "name": "Cola 330ml", "unit": "can" }, "store": { "id": 1, "name": "Main Store" }, "qty": 4, "threshold": 10 } ],
  "oversold":  [ { "product": {...}, "store": {...}, "qty": -3 } ],
  "recent_orders": [ { "id": 812, "order_no": "S1-R1-260827-0042", "total": "8500", "at": "2026-08-27T09:14:00+07:00", "cashier": "Koeuk", "store": "Main Store" } ],
  "offline_today": 2,
  "currency": { "code": "KHR", "symbol": "៛", "decimals": 0 }
}
```

`day` is the shop's today, so the app can label the card without guessing at timezones.

### Reports

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `reports/summary` | admin, manager | Totals for a range |
| GET | `reports/by-day` | admin, manager | Daily series |
| GET | `reports/by-product` | admin, manager | Top products |
| GET | `reports/by-payment` | admin, manager | Payment-method split |

All take `?from=YYYY-MM-DD&to=YYYY-MM-DD`. Defaults and clamping are **identical to the web** (`ReportController::range()` is extracted to a shared helper so the two cannot drift): default is the last 30 shop days, swapped if reversed, capped at 366.

Split into four rather than one because the app will draw them on separate screens and a 90-day by-product query is the one that gets slow.

**`reports/by-day`**
```json
{
  "from": "2026-07-29", "to": "2026-08-27",
  "rows": [ { "day": "2026-07-29", "orders": 12, "sales": "410000" }, ... ],
  "currency": {...}
}
```
Days with no sales are present with zeros — the app draws a chart, and a gap is not the same as a zero.

**`reports/by-product`** — `?limit=` up to 100, default 20:
```json
{ "rows": [ { "product": { "id": 3, "name": "Cola 330ml", "unit": "can" }, "qty": 340, "revenue": "1020000" } ] }
```

**`reports/by-payment`**
```json
{ "rows": [ { "method": "cash", "orders": 120, "amount": "3400000" }, { "method": "qr", ... } ] }
```

---

## Response conventions

- Top-level object always, never a bare array — so a field can be added later without breaking a client that expected a list.
- Money is a string. `"8500"` for riel, `"8.50"` for dollars. The `currency` block says which.
- Every money-bearing response carries `currency`. The app should never assume; the shop can switch.
- Snake_case keys throughout, matching the web's Inertia props so anyone reading both sees the same names.
- Lists are `rows` when they are a series, or a named key (`low_stock`) when they are a section of a screen.

---

## Files

```
routes/api.php                                  new — the v1 group
app/Http/Controllers/Api/V1/
    AuthController.php                          login, logout, logout-all, me
    DashboardController.php                     one action
    ReportController.php                        summary, byDay, byProduct, byPayment
app/Http/Resources/Api/                         thin JsonResource wrappers where a shape is reused
    UserResource.php
    CurrencyResource.php
app/Support/ReportRange.php                     extracted from ReportController::range(), shared by web + api
app/Models/User.php                             + HasApiTokens
config/sanctum.php                              published
database/migrations/..._create_personal_access_tokens_table.php
bootstrap/app.php                               register api routes; Sanctum's stateful middleware is NOT added — tokens only
tests/Feature/Api/
    AuthTest.php
    DashboardTest.php
    ReportsTest.php
```

`DashboardController` (web) is left exactly as it is. The API one calls the same `SalesReporter::for($user)` methods and returns JSON. If the two ever need to share shaping logic, that is the moment to extract it — not before.

---

## Tests

Every endpoint gets:

1. **Unauthenticated → 401.** No token, no data.
2. **Wrong role → 403.** A cashier hitting `reports/*`.
3. **Store scoping.** A manager sees only their store's figures; an admin sees all and may filter.
4. **Shape.** The keys documented above are present with the right types — money is a string, counts are integers.

Plus, specific to the reporting:

5. **Shop-day grouping.** A sale at 23:00 UTC lands on the *next* shop day, exactly as `ReportsTest` already proves for the web. The API must not reintroduce the UTC bug.
6. **Token lifecycle.** Login issues a token; logout revokes only that one; logout-all revokes every one; a revoked token is 401.
7. **Deactivated user** cannot log in and an existing token stops working on the next request.

---

## Later phases — sketched, not built

Each is a route group added to `api.php` and controllers under `Api/V1`. Nothing in phase 1 has to change to add them.

**Phase 2 — Point of Sale.** The existing `/pos/data/*` controllers already return JSON and are already offline-safe by `client_uuid`. They are re-exposed under `/api/v1/pos/*` with the token guard added to their middleware. The product feed already carries packs and the currency block. `OrderSyncService` is shared untouched.

**Phase 3 — Catalogue.** Products (with inline packs), categories, inventory movements including the container pair (`units_each`, `unit_label`, `loose`), add-stock. Form requests are reused — `ProductRequest` validates the same payload whether it arrives from Inertia or JSON.

**Phase 4 — Orders & Debts.** Order list with the web's filters, order detail with items and payments, debt list, settlement.

Each later phase gets its own plan section when it starts.

---

## Open questions

1. **Push notifications** for low stock or a large sale — out of scope for the API; would be a separate job + FCM/APNs. Flagging so it is not assumed to come free with this.
2. **Rate limiting.** Sanctum requests get Laravel's default `api` limiter (60/min). Fine for one shop; revisit if a device polls the dashboard aggressively.
3. **Pagination** on `recent_orders` — phase 1 returns the same 8 the web shows. Full order history with pagination is phase 4.
