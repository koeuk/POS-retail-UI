# Building against the POS Retail API

A walkthrough for someone writing an integration — a stock-taking script, a second till, a reporting dashboard. It goes in the order you will actually need things: get a token, read something, sell something, handle the failures.

For the exhaustive endpoint list see **[api.md](api.md)**; for the interactive version with _Try it out_, open **`/docs`** in the running app. This file is the narrative one.

---

## 1. Before you write any code

Ask the shop owner for **an account, not a token**. The API has no separate identity system: a token acts as a user, with that user's permissions ([roles-and-permissions.md](roles-and-permissions.md)). Two consequences worth planning around:

- **Ask for the narrowest role that does the job.** A stock report needs `inventory` and `reports`; it does not need `pos`. The owner grants exactly those on the Staff screen, and your integration physically cannot do more.
- **A cashier account is pinned to one store.** If your script must see every store, it needs an admin account — there is no "all stores" flag to pass.

Then check what you were actually given, before writing anything else:

```bash
curl -s -X POST https://shop.example/api/v1/auth/token \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"stock-bot@shop.test","password":"…","device_name":"stock-script"}'
```

The response carries a `can` map. Read it, don't assume it:

```json
{ "token": "7|Xy…", "user": { "id": 4, "role": "manager", "store_id": 1 }, "can": { "pos": false, "inventory": true, "reports": true } }
```

If `can.inventory` is `false`, stop and go back to the owner — every later call would just 403.

## 2. Holding the token

Store it like a password: environment variable or secrets manager, never in the repository, never in a client-side bundle. It does not expire on its own.

Send it on every request, with `Accept: application/json` — **without that header Laravel answers HTML redirects instead of JSON errors**, which is the single most common way an integration "mysteriously" fails to parse a response.

```bash
curl -s https://shop.example/api/v1/me \
  -H "Authorization: Bearer $POS_TOKEN" -H 'Accept: application/json'
```

Token issuing is throttled to **10 requests per minute**. Issue once, reuse; do not fetch a fresh token per call.

When the integration is retired, revoke it — `DELETE /api/v1/auth/token` — rather than leaving a live credential lying around.

## 3. Reading data

Every list endpoint shares one grammar, so learning it once covers products, customers, orders, debts and inventory.

```bash
# URL-encode the brackets, or pass -g to curl (its globbing eats raw [ ])
curl -s -g "https://shop.example/api/v1/products?filter[search]=cola&per_page=50&page=2" \
  -H "Authorization: Bearer $POS_TOKEN" -H 'Accept: application/json'
```

- `filter[...]` — the filters each endpoint allows. **An unknown filter is a `400`, not a silent ignore**; that is deliberate, so a typo surfaces immediately instead of quietly returning unfiltered data.
- `per_page` — one of 10, 20, 50, 100, 150, 200. Anything else silently falls back to 20.
- `page` — walk it until `next_page_url` is `null`.

```python
def all_pages(session, url):
    while url:
        body = session.get(url, headers=HEADERS).json()
        yield from body["data"]
        url = body["next_page_url"]        # None on the last page
```

**Money comes back as decimal strings** (`"2000.00"`) in the shop's own currency. Parse it as a decimal, never a float, and never assume cents — a riel shop's `"2000.00"` is ៛2,000, not $20.

**Dates you filter on are business days.** `filter[from]=2026-08-01` means the day the sale happened in the shop's timezone, not the day the row reached the server. For an offline-first till those differ, sometimes by days.

## 4. Selling: the sync contract

This is the one endpoint that changes the world, and it is built for unreliable networks. Three rules make it safe:

**Generate `client_uuid` yourself, before the request.** It is the idempotency key. Reuse the same one for every retry of the same sale.

```python
import uuid
order = {
    "client_uuid": str(uuid.uuid4()),   # generated ONCE, kept with the pending order
    "register_id": 1,
    "sale_type": "customer",
    "created_offline_at": "2026-09-01T10:15:00+07:00",
    "discount_amount": "0.00",
    "items": [{"product_id": 19, "product_name": "Wurkz", "qty": 2,
               "unit_price": "2000.00", "discount": "0.00"}],
    "payments": [{"method": "cash", "amount": "4000.00", "reference_no": None}],
}
r = session.post(f"{BASE}/api/v1/orders/sync", json={"orders": [order]}, headers=HEADERS)
```

**The response is always `200` — read the per-order results, not the status code.** One bad order never strands the batch:

```python
for result in r.json()["results"]:
    if result["status"] in ("created", "already_synced"):
        mark_done(result["client_uuid"], result["order_no"])
    else:                                    # "failed"
        log(result["message"])               # fix, then resend the SAME uuid
```

| `status`         | What it means                                              | What to do                                        |
| ---------------- | ---------------------------------------------------------- | ------------------------------------------------- |
| `created`        | Landed now. Totals recomputed, stock moved, number minted. | Mark synced.                                      |
| `already_synced` | This uuid arrived on an earlier flush.                     | Mark synced — **not** an error. Stock moved once. |
| `failed`         | This order alone was rejected; `message` says why.         | Fix the payload, resend the same uuid.            |

**The server owns the numbers.** Send `unit_price` and the server still recomputes the totals from the items; send `store_id` and a store-bound user's own store wins; the order number is minted server-side. Treat your local totals as a display value and the server's response as the truth.

Batches are capped at **200 orders**. Queue offline, flush in chunks.

### The two traps

**Stock can go negative, and that is on purpose.** A completed sale is never rejected for insufficient stock — the goods already left the shelf. Your integration must not treat a negative `qty` as corruption; it is the reconciliation list the shop works from (`filter[state]=oversold` on `/api/v1/inventory`).

**`stock_qty` on a product is a hint, not a reservation.** It was true when the response was built. Do not build "check stock, then sell" logic on it — sell, then read the result.

### Sale types change the money, not the shape

| `sale_type` | Payments mean                     | Effect                                                             |
| ----------- | --------------------------------- | ------------------------------------------------------------------ |
| `customer`  | Money taken now                   | Ordinary revenue.                                                  |
| `debt`      | The **deposit** (may be `"0.00"`) | Requires `customer_id`. The remainder is owed; capped at the bill. |
| `myself`    | Ignored                           | Stock moves, nothing counts as revenue.                            |

## 5. Collecting a debt

```bash
curl -s -g "https://shop.example/api/v1/debts?filter[state]=open" -H "Authorization: Bearer $POS_TOKEN" -H 'Accept: application/json'

curl -s -X POST "https://shop.example/api/v1/debts/42/settle" \
  -H "Authorization: Bearer $POS_TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"amount":"9000.00","method":"cash"}'
```

`paid_amount` is recomputed from the payments ledger on every settle, so a double-submitted payment cannot drift the balance — but it **will** record two payment rows. If your caller might retry, check `outstanding` in the response before sending again. Overpaying is refused with a `422`.

## 6. Handling failures properly

| Status | Meaning                                              | Right response                               |
| ------ | ---------------------------------------------------- | -------------------------------------------- |
| `401`  | Token missing, invalid, or revoked                   | Stop. Do not retry — get a new token.        |
| `403`  | Permission missing, or the account was deactivated   | Stop and tell a human. Retrying never helps. |
| `422`  | Validation. `errors` names the fields.               | Fix the payload; do not retry unchanged.     |
| `429`  | Throttled (token issue)                              | Back off, reuse the token you have.          |
| `503`  | Database hiccup on a write — **nothing was written** | Safe to retry after a pause.                 |

The one status to plan for carefully is **`403` after a period of working**: it usually means the owner deactivated the account or revoked the permission on the Staff screen. That is a deliberate kill switch, not a bug — surface it loudly rather than retrying in a loop.

## 7. A checklist before you ship

- [ ] Token in a secret store, not the repo
- [ ] `Accept: application/json` on every request
- [ ] `client_uuid` generated once per sale and persisted with it
- [ ] `already_synced` treated as success
- [ ] Money parsed as decimal, never float
- [ ] Pagination followed to `next_page_url: null`
- [ ] `403` surfaces to a human instead of retrying
- [ ] A negative stock quantity does not crash your reader

---

## What the API does not do

Catalogue writes, inventory movements, staff/store/settings management and customer edits are **web-only**. If your integration needs one of those, it needs a deliberate new endpoint with its own contract — see [api.md](api.md#not-exposed-by-design-today). Nothing here should be "just opened up" because a script found it convenient.
