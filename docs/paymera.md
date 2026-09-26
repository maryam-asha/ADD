# Paymera eGate — operating guide

Card payments for ADD OS, via Paymera eGate (API v4.0). This is the online
counterpart to the reception cash till: money taken by card becomes **ADD
Points** in a member's wallet, through the same `PointConversionService`
that governs cash.

The member mobile app is the only client. It asks this API for a payment
URL, opens it in a WebView, and polls for the result — it never talks to
Paymera, and the API key never leaves the server.

## Configuration

All of it is in `.env`; `config/paymera.php` reads it. Switching between
the test and production gateway is an `.env` change and nothing else.

| Variable | Test default | Notes |
|---|---|---|
| `PAYMERA_BASE_URL` | `https://egate-t.paymera.cc` | Production: `https://egate.paymera.cc` |
| `PAYMERA_API_KEY` | — | Basic Auth username, empty password. Never logged. |
| `PAYMERA_TERMINAL_ID` | — | 8 characters, always treated as a string |
| `PAYMERA_DEFAULT_LANG` | `en` | `en` or `ar`; the client may override per payment |
| `PAYMERA_TIMEOUT` | `15` | Seconds |
| `PAYMERA_PENDING_EXPIRY_MINUTES` | `60` | How long a pending payment is chased before write-off |
| `PAYMERA_SAVED_CARDS_ENABLED` | `false` | When true, `appUser` is sent as the member's id |
| `PAYMERA_CURRENCY` | `SYP` | The currency actually charged |
| `PAYMERA_CURRENCY_EXPONENT` | `2` | Charge = `amount × 10^exponent`; Paymera takes a whole number |

> **Paymera hosts appear only in `config/paymera.php`.** This is enforced,
> not conventional: `tests/Guards/NetworkIsolationTest.php` fails the build
> on any external host written under `app/`, `database/` or `routes/`.
> Never inline a gateway URL in application code.

If `PAYMERA_CURRENCY` is not the base currency, an **active exchange rate
must exist** for it, or payments cannot be priced and accepted payments
cannot be credited (they stay pending and the reconciler retries, logging
an alert on the `paymera` channel each time).

## What must be running

```bash
# Queue worker — the trigger endpoint dispatches SyncPaymeraPaymentJob to it.
php artisan queue:work

# Scheduler — runs paymera:reconcile every 5 minutes.
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Without the worker, trigger notifications queue up and nothing settles from
them. Without the scheduler, a member who closes their browser before
returning never gets credited. Both are required in production; the
reconciler alone is enough to eventually settle every payment, which is why
it exists.

After deploying, run:

```bash
php artisan migrate
php artisan permissions:sync    # required before anyone can reverse a payment
```

`permissions:sync` is not optional. Reversal is gated on
`permission:payments.reverse`, and an empty `permissions` table grants
nothing — **including to `admin`**.

## How it works

```
member app                  ADD Core                      Paymera
    │  POST member/payments     │                              │
    │─────────────────────────▶ │  create-payment ────────────▶│
    │  {redirect_url}           │◀──── {paymentId, url} ───────│
    │◀───────────────────────── │                              │
    │                                                          │
    │  opens url in WebView ──────────────────────────────────▶│
    │                           │                    card + OTP (5 digits,
    │                           │                    3 attempts, resendable)
    │                           │◀── triggerURL ───────────────│
    │                           │   (queues a status check)    │
    │◀── callbackURL ───────────────── (Finish / Cancel) ──────│
    │  GET member/payments/{id} │                              │
    │─────────────────────────▶ │  get-payment-status ────────▶│
    │◀──── {status} ─────────── │◀──── {status, amount, rrn} ──│
```

**Neither the callback nor the trigger reports the outcome.** The trigger
carries no result at all, and a member reaches the callback just as readily
by pressing Cancel as by paying. The only trustworthy statement is
`get-payment-status`, and `SyncPaymentStatus` is the only code that acts on
it. Everything else — callback, trigger job, reconciler, the app's own
status poll — funnels through that one class.

An accepted payment is credited only if its **amount and terminal id match
what we recorded**. A mismatch is never credited; it becomes
`needs_review` and logs an alert.

### Statuses

`pending` · `accepted` · `failed` · `canceled` · `reversed` · `expired` ·
`needs_review`

The last three are ours, not Paymera's. `canceled` means the *cardholder*
abandoned the payment; `reversed` means an admin cancelled an accepted one.

### Endpoints

| Method | Path | Who |
|---|---|---|
| `POST` | `/api/v1/member/payments` | member — opens a payment, returns `redirect_url` |
| `GET` | `/api/v1/member/payments` | member — own payment history |
| `GET` | `/api/v1/member/payments/{payment}` | member — syncs, then returns status |
| `GET` | `/api/v1/paymera/callback/{token}` | Paymera / the returning member |
| `GET\|POST` | `/api/v1/paymera/trigger/{token}` | Paymera |
| `GET` | `/api/v1/admin/payments` | admin/operations — filterable ledger |
| `POST` | `/api/v1/admin/payments/{payment}/reverse` | `permission:payments.reverse` |

The two public routes are keyed on a 64-character random `callback_token`,
never a payment id, so they cannot be enumerated. Both are rate limited
(60/min). CSRF does not apply: these are `api` routes, which Laravel does
not put behind `VerifyCsrfToken`.

## Manual testing on `egate-t`

Set `PAYMERA_BASE_URL=https://egate-t.paymera.cc` with test credentials
from `fmp-t.paymera.cc`, then walk each of these:

1. **Success.** Pay with a valid test card and the correct OTP. Expect
   `accepted`, points credited once, `rrn` populated.
2. **Wrong OTP three times.** Expect `failed`, no credit.
3. **Cancel.** Press Cancel on the hosted page. Expect `canceled`, no credit.
4. **Close the browser before returning.** Do not press Finish. The
   callback never fires; confirm `paymera:reconcile` settles it within five
   minutes.
5. **Trigger and callback together.** The normal case. Confirm exactly one
   wallet transaction — this is the double-credit path.
6. **Reversal.** Reverse an accepted payment from the dashboard. Expect
   `reversed`, and a compensating debit (the original credit stays in the
   member's history).
7. **Both languages.** `lang: ar` and `lang: en`; check the hosted page and
   the API's own messages.
8. **WebView.** Run the whole flow inside the app's WebView, not just a
   desktop browser — this is the only environment real members use.
9. **Plan purchase.** Buy a plan by card; confirm the membership activates
   and the net point balance is unchanged.
10. **Repriced plan.** Change a plan's price after starting a payment.
    Expect `needs_review` with the points kept by the member.

To watch what happens:

```bash
tail -f storage/logs/paymera-*.log
php artisan paymera:reconcile     # run the sweep by hand
```

## Before going to production

- [ ] The gateway is activated with the bank.
- [ ] This server's **static public IP** is registered with Paymera —
      production credentials are IP-bound, and an unregistered IP returns
      `ErrorCode 1` with a key that is otherwise perfectly valid.
- [ ] Production `PAYMERA_API_KEY` / `PAYMERA_TERMINAL_ID` taken from
      `fmp.paymera.cc` (not the `-t` dashboard).
- [ ] `PAYMERA_BASE_URL=https://egate.paymera.cc`.
- [ ] `APP_URL` is the real public URL — `callbackURL` and `triggerURL` are
      built from it, and Paymera must be able to reach them.
- [ ] `php artisan migrate` and `php artisan permissions:sync` have run.
- [ ] Queue worker and scheduler are running under a process supervisor.
- [ ] `PAYMERA_CURRENCY` matches what the bank actually settles in, and has
      an active exchange rate if it is not the base currency.
- [ ] One small **real** payment made end to end, then reversed, and both
      confirmed in `fmp.paymera.cc` as well as in `/api/v1/admin/payments`.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| `ErrorCode 1` on a known-good key | Server IP not registered (production only) |
| Payments stay `pending` forever | Queue worker or scheduler not running |
| Accepted but never credited; repeated alerts | No active exchange rate for `PAYMERA_CURRENCY` |
| `needs_review` with "amount mismatch" | The gateway's amount differs from ours — investigate before crediting by hand |
| 403 on reverse, even as admin | `permissions:sync` has not been run |
