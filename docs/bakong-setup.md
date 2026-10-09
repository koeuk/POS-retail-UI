# Bakong KHQR setup guide

A step-by-step guide to turning on Bakong QR payments that confirm themselves at the till. It follows the order you'll do things in: get the token, prepare the server, set up the POS, test, go live.

For how it works inside the code, see [payments.md](payments.md).

---

## What you get

- For each QR sale, the till shows a **KHQR code with the exact amount** and a 3-minute countdown.
- The customer scans it with **any Cambodian banking app** (ABA, ACLEDA, Wing, Bakong…).
- About 10–20 seconds after they pay, **the sale completes by itself**. The cashier doesn't need to check the phone.
- Offline, the till shows the shop's **fixed QR** instead and the cashier confirms by hand.

## Before you start

| You need                                      | Where it comes from                                                               |
| --------------------------------------------- | --------------------------------------------------------------------------------- |
| A **Bakong account ID**, e.g. `shopname@aclb` | The Bakong app, or your bank's app (find "Bakong ID" / "KHQR ID" in your profile) |
| An **email address** you can read             | Used to register for, and receive, the API token                                  |
| **Admin** login on the POS                    | Only admins can open Settings → Payments                                          |
| Server access (for the deploy steps)          | Whoever hosts the POS                                                             |

> The **Bakong app login** and the **developer portal login** are different accounts. The app uses your phone number and PIN; the portal uses an email. You need both, for different things.

---

## Step 1 — Register for an API token

1. In a web browser (not the app), open **<https://api-bakong.nbc.gov.kh>** and click **Register**.
2. Fill in:
    - **Organization**: your shop or business name
    - **Project**: e.g. `POS Retail – KHQR payments`
    - **Email**: one you check
3. Click **Register**, then verify the email when the message arrives (check spam).
4. **Wait for the token email.** It can take up to **24 hours**. The token is a long string of letters and numbers.

What the registration page tells you:

- The free tier allows **100 requests per day**. The POS is built around that; see [Daily limit](#the-100-a-day-limit).
- The token **expires**. When it does, go back to the same page, click **Token expired? Renew**, and a new one is emailed.

## Step 2 — Prepare the server

Do this once, on the machine that runs the POS.

```bash
# Create the qr_charges table
php artisan migrate

# Rebuild the front end if you pulled new code
npm run build
```

**Turn on the scheduler.** It records customers who pay just after the QR expired. Add this to the server's crontab:

```cron
* * * * * cd /path/to/POS-retail && php artisan schedule:run >> /dev/null 2>&1
```

**Optional `.env` settings** (the defaults are fine for most shops):

| Variable                                | Default    | When to change                                             |
| --------------------------------------- | ---------- | ---------------------------------------------------------- |
| `BAKONG_DAILY_LIMIT`                    | `100`      | You upgraded to a higher Bakong tier                       |
| `PAYMENTS_QR_TTL`                       | `180`      | QR lifetime in seconds                                     |
| `BAKONG_TOKEN`                          | —          | You'd rather keep the token in `.env` than in the database |
| `BAKONG_API_URL` / `BAKONG_SANDBOX_URL` | NBC's URLs | Only if NBC moves them                                     |

**Network:** the server needs outbound HTTPS to `api-bakong.nbc.gov.kh`. Bakong may accept requests only from certain IP addresses. If the connection test in Step 3 fails with a correct token, see [Troubleshooting](#troubleshooting).

## Step 3 — Set up the POS

Sign in as an **admin** and open **Settings → Payments**.

1. **QR payments**: choose **Bakong KHQR (auto-confirm)**.
2. **Receiving account**:
    - **Bakong account ID**: e.g. `shopname@aclb`
    - **Name shown**: what customers see in their banking app before paying (max 25 characters, **English letters only**)
    - **City**: e.g. `Phnom Penh` (max 15 characters)
    - _Merchant ID / acquiring bank_: only if your bank registered you as a merchant. Leave empty otherwise.
3. **Bakong API**:
    - **Environment**: **Sandbox** if the email says it's a test token, otherwise **Production**
    - **API token**: paste the token from the email
4. **At the till**: leave **Let cashiers confirm a QR payment by hand** on unless you have a reason to turn it off (see [FAQ](#faq)).
5. Click **Save**, then **Test saved settings**.
    - ✅ "Connected to Bakong… The token is accepted." means you're ready.
    - ❌ Any other message: see [Troubleshooting](#troubleshooting).

After saving, a **Saved static QR** appears on the right. **Scan it with your own banking app** and check that the shop name shows correctly. Don't pay it; just look.

## Step 4 — Test with real money

Use a small amount, e.g. ៛1,000.

1. On a till, reload **Point of Sale** so it picks up the new settings.
2. Add an item, press **Pay**, and choose **QR**.
3. A QR with the amount appears, with **Waiting for payment · 2:59**.
4. Pay it from your phone.
5. Within about 20 seconds the sale should **complete by itself**.
6. Check:
    - **Order history**: the sale appears with method QR.
    - **Activity log**: an entry **QR payment received (bakong)**.
    - **Settings → Payments**: **Checks used today** has gone up by about 4.

Then test the other cases:

| Test                                        | What should happen                                                          |
| ------------------------------------------- | --------------------------------------------------------------------------- |
| Let the QR expire without paying            | The code fades and a **New QR** button appears                              |
| Close the payment window mid-way            | Nothing is recorded; the QR is cancelled                                    |
| Turn off Wi-Fi, choose QR                   | The **shop's fixed QR** shows, plus a **Payment received** button           |
| Press **Payment arrived — confirm by hand** | The sale completes; the Activity log shows **QR payment confirmed by hand** |

## Step 5 — Go live

- [ ] Environment set to **Production** with the production token
- [ ] Scheduler cron running (`php artisan schedule:list` shows `payments:reconcile-qr`)
- [ ] One real payment tested end to end
- [ ] Cashiers briefed (below)
- [ ] A reminder set to check the token before it expires

---

## For cashiers

1. Press **Pay** and choose **QR**.
2. Turn the screen to the customer. They scan and pay.
3. **Wait.** The sale completes by itself when the money arrives. If you're in a hurry, press **Check now**.
4. If the QR expires, press **New QR**.
5. If the customer shows you a payment but the till hasn't confirmed it, **check the shop phone first**, then press **Payment arrived — confirm by hand**. Every hand confirmation is recorded with your name.
6. **Offline:** the till shows the shop's fixed QR. The customer types the amount. Check the shop phone, then press **Payment received**.

> Never accept a screenshot as proof. Only the shop phone, or the till confirming by itself, counts.

---

## The 100-a-day limit

Each "has it been paid?" question to Bakong uses one request. The POS keeps usage low:

| What                              | Requests                          |
| --------------------------------- | --------------------------------- |
| A typical QR sale (paid in ~30s)  | about **4**                       |
| A QR left unpaid until it expires | about **25**, plus **1** later check |
| **Test saved settings**           | 1                                 |

So the free tier covers roughly **20–25 QR sales a day**. **Settings → Payments → Checks used today** shows the running count.

When the limit is reached, the till **stops asking Bakong** and shows _"Today's Bakong check limit is used up — confirm QR payments by hand until tomorrow."_ Sales keep working; confirmation just goes back to the shop phone. The count resets at midnight, shop time.

If you regularly run out, apply for a higher tier on the Bakong portal, then set `BAKONG_DAILY_LIMIT` in `.env` to the new number.

## Renewing the token

When the token expires, the till shows _"Bakong refused the token — it has probably expired."_

1. Open **<https://api-bakong.nbc.gov.kh>**, go to **Register**, and click **Token expired? Renew**.
2. Enter the same email. A new token is emailed to you.
3. In **Settings → Payments**, paste it into **API token**. The old one is replaced; leaving the field blank keeps the old one.
4. **Save**, then **Test saved settings**.

Until then, QR still works; cashiers confirm by hand.

---

## Troubleshooting

| Message or symptom                                   | Likely cause                                                        | Fix                                                                                                                                                              |
| ---------------------------------------------------- | ------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| _Bakong refused the token — it has probably expired_ | Token expired, pasted incompletely, or wrong environment            | Renew the token; check the whole string was pasted; switch Sandbox ↔ Production                                                                                 |
| _Cannot reach Bakong right now_                      | Server has no internet, or Bakong is blocking your IP               | Check `curl -I https://api-bakong.nbc.gov.kh` on the server. If that works, ask NBC whether your server IP must be whitelisted (a Cambodian IP may be required). |
| _Bakong is having trouble (HTTP 5xx)_                | Bakong outage                                                       | Wait. Cashiers confirm by hand meanwhile.                                                                                                                        |
| _Today's Bakong check limit is used up_              | 100 requests used                                                   | See [the limit](#the-100-a-day-limit)                                                                                                                            |
| _…the amount or account does not match_              | A payment was found but for a different amount or account           | **Don't hand over goods.** Check the shop account. Make sure the account ID in settings is yours.                                                                |
| Customer's app says the QR is invalid                | Name or city has non-English characters, or the account ID is wrong | Fix it in Settings → Payments; scan the preview QR to check                                                                                                      |
| Till shows the fixed QR, not one with the amount     | Till is offline, provider isn't Bakong, or the till hasn't reloaded | Check the connection; reload Point of Sale                                                                                                                       |
| Paid, but the till never confirmed                   | Customer paid after the cashier closed the window                   | The scheduler finds it within ~10 minutes (**QR payment received** in the Activity log). Make sure cron is running.                                              |
| No QR at all: _No shop QR set up yet_                | Account ID not saved                                                | Enter it in Settings → Payments                                                                                                                                  |

Useful commands:

```bash
php artisan payments:reconcile-qr          # check late payments now
php artisan schedule:list                  # confirm the scheduler knows the job
tail -f storage/logs/laravel.log           # server-side errors
```

---

## FAQ

**Is the token safe?**
It's stored encrypted and never sent to the tills or shown again on screen. The Activity log records only _that_ it changed, never the token itself.

**Who can change these settings?**
Admins only. Every change (including the account ID, which decides where money goes) is written to the Activity log.

**Should I turn off hand confirmation?**
Only if staff are accepting fake payment screenshots. With it off, a slow bank or a used-up daily limit means the cashier has to cancel QR and take cash instead.

**KHR or USD?**
The QR uses the shop's currency from **Settings → Shop**. Riel amounts are whole numbers.

**Can I use ABA PayWay or another provider instead?**
Yes, the POS is built so another provider can be added as one new class. See [payments.md → Adding a provider](payments.md#adding-a-provider-eg-aba-payway).

**Where do I see QR payments?**
Order history (method QR), the Activity log (money events), and Reports (payment-method breakdown).
