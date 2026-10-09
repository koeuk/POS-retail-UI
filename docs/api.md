# POS Retail — Token API (v1)

The same app through a second door. Every endpoint lives under `/api/v1`, speaks JSON, and sits behind **the same permission gates as its web screen** — there is no separate API permission model to configure or drift. Access control itself is documented in [roles-and-permissions.md](roles-and-permissions.md); this file is the endpoint reference. **New to it? Start with the [integration guide](api-guide.md)**, which walks through getting a token, reading, selling and handling failures in order.

Implemented with Laravel Sanctum personal access tokens: [routes/api.php](../routes/api.php), controllers in [app/Http/Controllers/Api](../app/Http/Controllers/Api).

**Interactive docs** (Scribe, staff-only): **`/docs`** in the running app — with _Try it out_, plus the machine-readable specs at `/docs.openapi` (OpenAPI 3) and `/docs.postman` (Postman collection). Regenerate after changing an API controller or its annotations: `php artisan scribe:generate`. The rendered page, its assets and the spec files are **gitignored build output** — on a fresh clone the three `/docs*` routes 500 until that command has been run once.

## Conventions

**Headers.** Send both on every request:

```
Authorization: Bearer <token>
Accept: application/json
```

**Errors** are standard Laravel JSON:

| Status | Meaning                                                  | Body                                               |
| ------ | -------------------------------------------------------- | -------------------------------------------------- |
| `401`  | No/invalid/revoked token                                 | `{ "message": "Unauthenticated." }`                |
| `403`  | Token's user lacks the permission, or was deactivated    | `{ "message": "…" }`                               |
| `404`  | Model not found, or scoped away from this user           |                                                    |
| `422`  | Validation                                               | `{ "message": "…", "errors": { "field": ["…"] } }` |
| `429`  | Throttled (token issue: 10/min)                          |                                                    |
| `503`  | Database failure on a write — nothing was changed, retry | `{ "message": "…" }`                               |

**Pagination.** List endpoints return the standard Laravel paginator (`data`, `current_page`, `per_page`, `total`, `links`, …). Page size comes from `?per_page=` and is whitelisted against `App\Support\PerPage::OPTIONS` (10, 20, 50, 100, 150, 200) — anything else silently falls back to 20. `?page=` selects the page.

**Filters** use the Spatie query-builder grammar, identical to the web screens: `?filter[search]=cola&filter[status]=active`. An unknown filter or sort key is refused with a `400`, not ignored. Remember to URL-encode the brackets (`filter%5Bsearch%5D=`) — or pass `-g` to curl, whose default globbing eats raw `[...]`.

**Money** is decimal strings in the shop's own currency (`"2000.00"` is ៛2,000 when the shop runs riel — riel amounts still carry two zero decimals on the wire). **Dates** are ISO-8601; daily figures bucket by the shop's business day, not server UTC.

**Store scoping.** Admin tokens see every store. Manager and cashier tokens are pinned to their user's store for orders, debts, inventory and sync — the same rule as their session.

---

## Auth

### `POST /api/v1/auth/token` — issue a token

No auth required; throttled to 10/min per IP.

```json
{ "email": "owner@shop.test", "password": "…", "device_name": "warehouse-laptop" }
```

`201`:

```json
{
    "token": "1|kANNnRWFrw…",
    "user": { "id": 1, "name": "bobo", "email": "…", "role": "admin", "store_id": null },
    "can": { "pos": true, "orders": true, "debts": true, "reports": true, "…": true }
}
```

Wrong credentials → `422`. A **deactivated account** is refused here, and any token it already holds starts answering `403` — deactivation on the Staff screen shuts an integration out on its next request.

`device_name` labels the token for future revocation lists; name the machine, not the person.

### `DELETE /api/v1/auth/token` — revoke

Revokes the very token that authenticated the request. `200 { "ok": true }`; the token answers `401` from then on.

### `GET /api/v1/me`

The token's user and the resolved permission map — the API twin of the web's `auth.can`. Permissions are resolved **at request time**, so a grant or revoke on the Staff screen changes what this returns (and what every gate answers) without reissuing the token.

---

## Catalogue — permission `products` / `categories`

### `GET /api/v1/products`

Filters: `filter[search]` (name, SKU, barcode), `filter[category_id]`, `filter[status]` (`active`/`inactive`). Paginated; newest first. Base products only — a pack rides along inside its parent:

```json
{
    "data": [
        {
            "id": 19,
            "name": "Wurkz",
            "sku": "SKU-0010",
            "barcode": null,
            "sell_price": "2000.00",
            "unit": "can",
            "case_size": null,
            "is_active": true,
            "stock_qty": "17",
            "category": { "id": 2, "name": "ភេសជ្ជៈ" },
            "packs": [{ "id": 23, "name": "6 cans", "units_per_pack": 6, "sell_price": "11000.00", "is_active": true }]
        }
    ],
    "per_page": 20,
    "total": 1
}
```

`stock_qty` sums every store. It is a report figure, not a promise — stock is only ever decided server-side at sync time.

### `GET /api/v1/products/{id}`

One product with `category`, `packs`, and per-store `stocks` rows (`store_id`, `qty`, `low_stock_threshold`).

### `GET /api/v1/categories`

All categories with `products_count`, alphabetical. Not paginated.

---

## Customers — permission `customers`

### `GET /api/v1/customers`

`filter[search]` matches name, phone or email. Rows carry `orders_count` and `spent_total`.

### `POST /api/v1/customers`

```json
{ "name": "Dara", "phone": "012 345 678" }
```

`201` with the customer. Validation as the web screen; a database failure answers `503` with nothing written.

---

## Orders — permission `orders`

### `GET /api/v1/orders`

Filters: `filter[search]` (order no. or customer name), `filter[status]` (`completed`/`refunded`/`void`), `filter[sale_type]` (`customer`/`debt`/`myself`), `filter[from]` / `filter[to]` (Y-m-d, **business day** of the sale, not arrival time). Newest first by when the sale actually happened. Rows include `cashier`, `store`, `customer`, `payments`, `items_count`.

### `GET /api/v1/orders/{id}`

The full order: `items`, `payments`, `customer`, `cashier`, `store`, plus computed `outstanding` (what a debt still owes; `"0.00"` otherwise). `404` if the order belongs to a store the token cannot see.

---

## Selling — permission `pos`

### `POST /api/v1/orders/sync`

**Identical contract to the till's `/pos/data/orders/sync`** — same request rules, same responses, same idempotency. Batch of up to 200 orders:

```jsonc
{
    "orders": [
        {
            "client_uuid": "3f6c…", // generated by the caller — the idempotency key
            "store_id": 1, // ignored for store-bound users; their store wins
            "register_id": 1,
            "customer_id": null, // required when sale_type is "debt"
            "sale_type": "customer", // "customer" | "debt" | "myself"
            "created_offline_at": "2026-09-01T10:15:00+07:00",
            "discount_amount": "0.00",
            "items": [{ "product_id": 19, "product_name": "Wurkz", "qty": 2, "unit_price": "2000.00", "discount": "0.00" }],
            "payments": [{ "method": "cash", "amount": "4000.00", "reference_no": null }],
        },
    ],
}
```

Always `200`, one result per order — a malformed order never strands the rest:

```json
{ "synced_at": "…", "results": [{ "client_uuid": "3f6c…", "status": "created", "order_id": 42, "order_no": "S1-R1-260901-0007", "message": null }] }
```

| `status`         | Meaning                                                                                            |
| ---------------- | -------------------------------------------------------------------------------------------------- |
| `created`        | Landed now; totals recomputed server-side, stock moved, number minted.                             |
| `already_synced` | This `client_uuid` landed on an earlier flush — the retry collapsed into it. Stock moves **once**. |
| `failed`         | This order only; `message` says why. Fix and resend the same `client_uuid`.                        |

Sale-type money rules: `customer` payments are recorded as sent; on a `debt` the payments are the **deposit** — capped at the bill, zero-amount rows skipped, the remainder owed; `myself` moves stock but records no revenue and no payment rows.

---

## Debts — permission `debts`

### `GET /api/v1/debts`

`filter[state]` = `open` (default — still owed) or `settled`; `filter[search]` matches order no., customer name or phone. Each row carries the whole story: `customer`, `items`, `payments` (the deposit included).

### `POST /api/v1/debts/{order}/settle`

```json
{ "amount": "9000.00", "method": "cash", "reference_no": null }
```

`200`:

```json
{ "order_no": "S1-R1-260901-0001", "paid_amount": "15000.00", "outstanding": "0.00", "settled": true }
```

Paying more than is owed → `422` ("That is more than is owed."). Method must be `cash`, `card` or `qr` — settling a debt on credit is circular. `paid_amount` is recomputed from the payments ledger, so a double-submit cannot drift it.

---

## Inventory — permission `inventory`

### `GET /api/v1/inventory`

Read-only shelf truth per product × store. Filters: `filter[search]`, `filter[store_id]`, `filter[state]` (`low`/`out`/`oversold`); `sort=qty` (default, lowest first) or `sort=-qty`. Movements are **not** writable over the API — record them on the web screen or let sync move stock.

---

## Reports — permission `reports`

### `GET /api/v1/reports/summary?from=2026-08-01&to=2026-08-31`

Defaults to the last 30 days; clamped to a year. Only completed, non-`myself` orders count, bucketed by business day:

```json
{
    "from": "2026-08-01",
    "to": "2026-08-31",
    "totals": { "orders": 13, "sales": "232000.00", "items": 47, "basket": "17846.15" },
    "by_day": [{ "day": "2026-08-28", "orders": 5, "sales": "76000.00" }],
    "by_product": [{ "product_name": "Wurkz", "qty": 9, "revenue": "18000.00" }],
    "by_payment": [{ "method": "cash", "count": 7, "amount": "96000.00" }],
    "debts_outstanding": { "count": 8, "owed": "101000.00" }
}
```

---

## Not exposed (by design, today)

Catalogue writes, inventory movements, staff/store/settings management, and customer edit/delete stay web-only — the API covers reading and selling. If an integration needs one of these, it gets its own deliberate contract; nothing here should be "just opened up".
