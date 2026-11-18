# Paymera eGate integration — plan

**Status:** in progress, started 2026-09-24. **Owner:** Maryam Asha.
**Scope:** card payment (Paymera eGate API v4.0) as an online funding path
for the points wallet, and as a direct plan-purchase path, for the member
mobile app only.

## Phase 0 findings

| Item | Finding |
|---|---|
| Laravel / PHP | 12.64.0 / `^8.3` |
| DB | MySQL (tests: in-memory SQLite, `phpunit.xml`) |
| Queue | `database` — worker required |
| Cache | `database` — `Cache::lock()` available |
| Scheduler | `routes/console.php`, several `everyFiveMinutes()->withoutOverlapping()` precedents |
| Permissions | `spatie/laravel-permission`; `permission:` pilot on Branches only |
| Outbound HTTP precedent | `TTLockClient`, `HalaSenderOtpProvider` — Laravel `Http`, custom exceptions, config under `services.*`, driver bound in a ServiceProvider |
| Existing payment gateway | **none** |

### The `Order` conflict — resolved

The task brief assumed an `Order` model with a `total` column. **Neither
exists.** This is a coworking platform whose money model is different in kind:

- The ledger is **point-denominated**. `wallet_transactions.amount` is a
  signed integer *count of points*, not a currency amount.
- Currency converts to points **exactly once, on the way in**, through
  `PointConversionService`. `tests/Guards/PointConversionIsCentralizedTest.php`
  forbids any other class from reading `point.rate_usd`.
- The only existing funding path is **cash at reception**
  (`WalletTopUpController`, `PaymentMethod: cash|sham|mtn|syriatel`).
- Memberships are bought by **debiting points**
  (`MembershipActivationService`), never by paying money directly.

So "mark the order paid" has no meaning here. Paymera becomes a **second
funding path**, the online sibling of the reception cash till, reusing the
same conversion and the same ledger.

### Decisions taken (confirmed with the owner, 2026-09-24)

1. **Purpose.** A payment has a purpose: `wallet_top_up` **or**
   `plan_purchase`. Both credit points; `plan_purchase` additionally
   activates the plan.
2. **Surface.** Mobile app only. JSON throughout — no Blade views, which
   keeps `CLAUDE.md`'s "JSON only, no server-rendered views" rule intact.
3. **Activation failure after a successful charge.** Points are credited
   **unconditionally** on `status = A`. If plan activation then fails, the
   member keeps the points and the payment is flagged `needs_review`. The
   customer's money is never silently lost, and this matches points being
   the system's unit of account. No automatic bank reversal.
4. **Admin reversal authorization.** Follows the Branches pattern:
   `permission:payments.reverse`, registered in `PermissionSyncService`'s
   manual list. Deliberately extends the RBAC pilot by one resource.

### Binding constraints discovered

These are repo guards that fail the build, not style preferences:

- **`NetworkIsolationTest`** — any external host referenced under `app/`,
  `database/` or `routes/` fails the suite. Paymera's hosts therefore live
  **only in `config/paymera.php`** (`config/` is excluded from the scan),
  and `egate.paymera.cc` / `egate-t.paymera.cc` are added to that guard's
  `ALLOWLIST` with a justification. *(This is why no URL may be inlined in
  the client class.)*
- **`MoneyIsDecimalOnlyTest`** — no `->float()`/`->double()` in any
  migration. Paymera's `amount` is a minor-unit integer, so `bigInteger`;
  any currency figure is `decimal`.
- **`NoNewMysqlEnumColumnsTest`** — no `->enum(...)`. Status is a `string`
  column + PHP backed enum cast.
- **`EnumColumnsHaveBackedEnumCastsTest`** — that cast is mandatory.
- **`PointConversionIsCentralizedTest`** — only `PointConversionService`
  may read the point rate. The payment code converts through it, never
  by arithmetic of its own.
- **`WalletBalanceIgnoresAuditColumnsTest`** — audit columns on
  `wallet_transactions` must not affect balance computation.

## Design

### Money direction

```
member picks amount/plan (mobile)
        │
        ▼
StartPayment ── amount read from DB (plan price) or validated top-up input
        │        converted to Paymera minor units, stored on the payment row
        ▼
Paymera hosted page (card + OTP)  ──trigger──▶ queue SyncPaymeraPaymentJob
        │                                              │
        └──callback──▶ member app polls status         │
                                                       ▼
                                          SyncPaymentStatus  ◀── paymera:reconcile
                                                       │
                                     verify A + amount + terminalId
                                                       │
                                     ┌─────────────────┴──────────────────┐
                                     ▼                                    ▼
                          credit points (always)                  mismatch → needs_review
                                     │                            (no credit, alert logged)
                          purpose = plan_purchase?
                                     │
                          activate → ok | fail → needs_review (points kept)
```

`SyncPaymentStatus` is the **only** writer of terminal state. The callback,
the trigger job and the reconcile command all funnel through it.

### Amount handling

`plan.price_points` is the authoritative price for a `plan_purchase`; the
charged currency amount is derived from it via `PointConversionService`, so
the amount charged can never disagree with the points granted. For a
`wallet_top_up` the member supplies an amount, validated exactly as the
reception till validates it (including the "worth less than one point"
refusal). In both cases the amount sent to Paymera is recomputed
server-side and stored before redirect; the browser never supplies it.

### Files

**New**
- `config/paymera.php`
- `app/Domain/Finance/Enums/PaymentStatus.php` (pending/accepted/failed/canceled/reversed/expired/needs_review)
- `app/Domain/Finance/Enums/PaymentPurpose.php` (wallet_top_up/plan_purchase)
- `app/Domain/Finance/Models/Payment.php`
- `app/Domain/Finance/DataTransferObjects/{CreatedPayment,PaymentStatusResult}.php`
- `app/Domain/Finance/Exceptions/{PaymeraException,PaymeraUnauthorizedException,PaymeraRequestFailedException}.php`
- `app/Domain/Finance/Services/PaymeraClient.php`
- `app/Domain/Finance/Services/StartPayment.php`
- `app/Domain/Finance/Services/SyncPaymentStatus.php`
- `app/Domain/Finance/Services/ReversePayment.php`
- `app/Events/Finance/{PaymentAccepted,PaymentFailed,PaymentReversed}.php`
- `app/Listeners/Finance/{CreditWalletOnPaymentAccepted,...}.php`
- `app/Jobs/SyncPaymeraPaymentJob.php`
- `app/Console/Commands/ReconcilePaymeraPayments.php`
- `app/Http/Controllers/Api/V1/Member/PaymentController.php`
- `app/Http/Controllers/Api/V1/Paymera/PaymeraCallbackController.php`
- `app/Http/Controllers/Api/V1/Admin/PaymentController.php`
- `app/Http/Requests/Member/StartPaymentRequest.php`
- `app/Http/Resources/Finance/PaymentResource.php`
- `database/migrations/*_create_payments_table.php`
- `database/factories/PaymentFactory.php`
- tests per §Phase 9

**Modified**
- `.env.example`, `config/logging.php` (paymera channel), `config/services.php` (none — paymera gets its own config file)
- `app/Domain/Finance/Enums/PaymentMethod.php` — add `Paymera`
- `routes/api/v1/member.php`, `routes/api/v1/admin.php`, `routes/api.php` (public gateway routes)
- `routes/console.php` — schedule `paymera:reconcile`
- `lang/{en,ar}/api.php` — a `payment` group
- `app/Services/Permissions/PermissionSyncService.php` — `payments.*`
- `tests/Guards/NetworkIsolationTest.php` — allowlist the two Paymera hosts

## Outcome

Implemented as planned. Two things were learned during the build that the
plan above did not anticipate, both recorded here because they changed the
code:

**1. Listeners were registered twice, and it double-debited.** Laravel 12
auto-discovers a listener from its `handle()` parameter type. The explicit
`Event::listen()` calls added in `AppServiceProvider` therefore registered
each payment listener a second time, and every event fired both copies. The
credit listener's `hasBeenCredited()` guard silently absorbed its own
double-fire, so the bug was invisible on that side; the reversal listener
had no such guard and wrote **two** clawback rows, taking double the points
off the member. Fixed by removing the explicit registrations, adding an
idempotence check to the reversal listener as well, and pinning the
listener count in `PaymeraReversalTest::test_each_payment_listener_is_registered_exactly_once`.

**2. A missing exchange rate silently stalls an accepted payment.** If
`PAYMERA_CURRENCY` is not the base currency and has no active rate, the
credit throws, the transaction rolls the `Accepted` write back, and the
payment returns to pending — correct behaviour for a transient fault, but
it would retry forever in silence for this persistent one.
`SyncPaymentStatus::settleAccepted()` now logs an alert before rethrowing,
and `docs/paymera.md` lists it under troubleshooting.

### Assumptions

- Paymera `amount` is an integer in the currency's **minor unit**; the
  doc's example (`1860000`) and "no decimals or separators" support this.
  The charge currency is configurable (`PAYMERA_CURRENCY`, default SYP)
  with its minor-unit exponent, so the conversion is explicit rather than
  assumed to be ×100.
- `triggerURL`/`callbackURL` carry an opaque random token, not the
  payment id, so the URLs cannot be guessed or enumerated.
- Reversal is admin-initiated only, never automatic.
