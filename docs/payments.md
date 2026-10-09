# QR payments (KHQR / Bakong)

How the till takes QR payments, how Bakong auto-confirm works, and how to add another provider.

**Setting it up for a shop?** Follow the step-by-step [Bakong setup guide](bakong-setup.md).

## Setting it up

1. **Bakong account.** The shop needs a Bakong account ID such as `shopname@aclb`, from the Bakong app or any member bank. Money lands here.
2. **API token.** At <https://api-bakong.nbc.gov.kh>, click **Register** and enter your organisation, project and email, then verify the email. The token arrives **by email within 24 hours**. When it expires, use **Renew** on the same page (it's emailed again) and paste the new one in Settings → Payments. The free tier allows **100 requests per day**.
3. **Settings → Payments** (admin only):
    - Choose **Bakong KHQR (auto-confirm)**.
    - Enter the account ID and the name and city shown to customers (English letters only).
    - Paste the API token. Use the **sandbox** environment while testing.
    - Save, then **Test saved settings**.
4. **Scheduler.** Late payments are picked up by `payments:reconcile-qr` every ten minutes, so `php artisan schedule:run` must be on cron in production.

Without an API key, choose **Static KHQR (cashier confirms)**. The till shows the shop's fixed QR and the cashier confirms each payment by hand.

## How a sale flows

```
Till                              Server                           Bakong
 │ QR selected                     │                                │
 │ POST /pos/data/qr/charges ─────▶│ build KHQR (amount, expiry)     │
 │ ◀── { svg, reference=md5 } ─────│ save qr_charges row (pending)   │
 │ show QR, poll every 8s          │                                │
 │ GET  /pos/data/qr/charges/{id} ▶│ POST /v1/check_transaction_by_md5 ▶│
 │ ◀── { status: paid } ───────────│ ◀─ responseCode 0 + data ───────│
 │ sale queued with method=qr,     │                                │
 │ reference_no=md5 → offline sync ▶│ order created, charge.order_id set │
```

- **The QR is built locally.** A KHQR is an EMVCo string (`app/Payments/Khqr/KhqrPayload.php`), and Bakong only answers "has the QR with this MD5 been paid?".
- **Paid means it matches.** The amount, currency and receiving account must match the charge. A mismatch sets the charge to `failed` and the cashier is warned.
- **Offline:** the till shows the static QR, which is cached in the feed as SVG, and the cashier confirms by hand. If the connection drops while a dynamic QR is on screen, the confirmation keeps the charge reference, so reconcile can still verify the payment with Bakong later.
- **Manual confirm** of a dynamic QR is allowed by default (for a slow bank) and every one is logged as `qr_manual`. Switch it off in Settings → Payments.
- **Rate limits (100/day on the free tier):** the till waits 10s before its first check and then checks every 8s (about 4 calls per sale), and has a **Check now** button. The server makes at most one call per charge every 8s, and reconcile asks about each unpaid charge only once, a minute after it expires. A daily counter (`BAKONG_DAILY_LIMIT`, default 100) stops calls at the limit; after that the till falls back to confirming by hand. Settings → Payments shows today's usage.

## Where things are

| Concern                            | File                                                             |
| ---------------------------------- | ---------------------------------------------------------------- |
| Provider registry                  | `config/payments.php` → `providers`                              |
| Contract                           | `app/Payments/QrGateway.php`                                     |
| Bakong / static drivers            | `app/Payments/Gateways/*`                                        |
| Charge lifecycle, audit, reconcile | `app/Payments/QrPayments.php`                                    |
| Settings (token stored encrypted)  | `app/Payments/PaymentSettings.php`, `Settings/PaymentController` |
| Till endpoints (`permission:pos`)  | `PosQrController`, `routes/web.php` under `pos/data/qr`          |
| Till UI                            | `resources/js/Pos/components/QrPayPanel.vue`                     |
| Charge ledger                      | `qr_charges` table, `App\Models\QrCharge`                        |

Audit events (money log): `qr_paid`, `qr_failed`, `qr_manual`, `payment_settings`. The API key is never logged; the log only records a fingerprint showing it changed.

## Adding a provider (e.g. ABA PayWay)

1. Create `app/Payments/Gateways/PaywayGateway.php` implementing `QrGateway`:
    - `createCharge()` calls the provider API and returns `IssuedCharge(qr, reference)`.
    - `checkStatus()` maps the provider's answer to a `StatusResult`.
    - Credentials go through `PaymentSettings` (`putSecret` / a private getter).
2. Add `'payway' => PaywayGateway::class` to `config/payments.php`.
3. Add its credential fields to `settings/Payments.vue` and the validation in `PaymentController`.

The till, sync, reconcile and audit need no changes. If the provider offers webhooks, add a route that finds the charge by `reference` and calls `QrPayments::refresh()`.
