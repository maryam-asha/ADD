# Unified Payments Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Each task: failing tests first, then code, then one or more commits. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Every purchasable operation — plan purchase, booking, event registration, wallet top-up, and the amount due on a coworking session — can be paid by wallet points (with a sufficiency check), by cash at reception, or through Paymera, and a new payment method can be added later by writing one driver class and one config entry, without touching any operation.

**Approved design (Arabic, with the user's decisions):** `C:\Users\User\.claude\plans\qr-rustling-eich.md`. That document is the spec; this plan is its argument. The user's decisions it records:
- A booking may be confirmed unpaid.
- An event registration may be confirmed unpaid, but the member cannot attend until paid. A door check-in refuses an unpaid paid-event registration.
- A plan bought with cash is a pending request; the plan is not activated until reception confirms payment, and the request expires.
- Payment state is visible on every operation.
- Reception enters the amount received. Any overpayment becomes wallet points.
- Events and plans may be paid from the same wallets as bookings: personal, or any company the member belongs to, with an explicit choice when several apply.
- The coworking-session amount due is in scope.

**Architecture:** Points stay the only ledger. Money arriving by any method is first credited as points to a wallet, and the operation is then paid by debiting points. The card plan purchase already works this way. One `payments` table records every payment, whatever the method, and links polymorphically to what it paid for. Payment methods are drivers behind one interface, resolved from `config/payments.php`. Each payment purpose has a fulfiller that knows what is due and what happens once paid.

**Tech stack:** Laravel 12, PHP 8.3 (PATH has 8.2.4, so composer needs `--ignore-platform-reqs`), Sanctum, spatie/activitylog, PHPUnit 11 on in-memory SQLite.

## Global Constraints

- **Points are the only ledger.**
  - Money in → `WalletService::creditGeneral()` to a wallet, then `forceFill` the audit columns on that transaction: `payment_method`, `payment_currency`, `payment_amount` (in the charged/received currency), `exchange_rate_to_base`, `point_rate_usd`, `performed_by_user_id` (null for gateways).
  - An operation is paid by `WalletService::debit()` in the fulfiller's category. Audit columns never feed a balance (`WalletBalanceIgnoresAuditColumnsTest`).
- **Rounding and conversion.** Credits round down, debits round up. Every points↔money conversion goes through `PointConversionService` (`PointConversionIsCentralizedTest`). bcmath strings, never float, for money (`MoneyIsDecimalOnlyTest`).
- **Enum columns.** New enum-backed columns are strings with a backed-enum cast, registered in `tests/Guards/EnumColumnsHaveBackedEnumCastsTest::EXPECTED_CASTS`. No MySQL enum columns (`NoNewMysqlEnumColumnsTest`).
- **Domain boundary.** `DomainLayerBoundaryTest`: Ecosystem and Experience import nothing from Booking, Membership, Finance or Access. Code that pays for an event lives in `App\Domain\Finance` or `app/Services/Experience`, never in `app/Domain/{Ecosystem,Experience}`.
- **Payment listeners** are auto-discovered. Never register them in `AppServiceProvider` (explicit registration made them fire twice once already).
- **Morph map.** Never alias `User`. `payable` morphs use the existing aliases `booking`, `walkin_session`, `event_attendee`.
- **Backward compatibility — the shipped mobile app must keep working:**
  - `POST member/payments` without `method` = Paymera, same request and response keys as today.
  - `/api/v1/paymera/callback/{token}` and `/api/v1/paymera/trigger/{token}` are unchanged.
  - `POST member/bookings` and `/batch` without `payment_method` keep today's `routePayment` semantics.
  - `POST reception/{bookings|walk-ins}/{id}/settle-payment` still accepts just `{payment_method}`.
  - `paymera:reconcile` keeps its name and schedule.
  - Event registration without `payment_method` behaves as today (wallet).
- **Response conventions.** Update-style endpoints return `{"message": __('api.<group>.<key>')}`; create endpoints return data. Every new message key exists in both `lang/en/api.php` and `lang/ar/api.php`. There is ONE top-level group per name in each lang file — a second group with the same name silently replaces the first.
- **Insufficient points** anywhere a member pays by wallet → 422 `{"message": __('api.payment.insufficient_points'), "current": int, "required": int, "shortage": int}`. The exception is event registration, which keeps its existing `points` validation-error shape.
- **Start-payment response** (member): 201 `{"data": {"payment": PaymentResource, "redirect_url": string|null, "next": "redirect"|"done"|"pay_at_reception"}}`.
- **Local times.** User-supplied wall-clock times go through `InterpretsLocalTimes`.
- **Tests.**
  - In-memory SQLite. The pre-existing baseline is 17 failures (audit-log `'1'` vs `1`, the `EventAttendee` enum guard, and a few Notification/Ecosystem tests). Judge by "no new failures", never "all green".
  - Clocks are set in UTC: `Carbon::setTestNow(Carbon::parse('… local …', 'Asia/Damascus')->utc())`.
  - Paymera is faked exactly as `tests/Feature/Finance/PaymeraPaymentTest.php` does: `Http::fake` URL patterns plus `config(['paymera.*' => …])`.
- **Never** run `migrate`, `migrate:fresh`, `db:wipe` or `db:seed` — `.env` is the user's MySQL. `RefreshDatabase` migrates SQLite. **Never** read `vendor/`, `storage/logs`, `storage/framework` or `bootstrap/cache`.
- **Commit trailer:** `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Existing code every task leans on

| Area | Where |
|---|---|
| Card payments | `app/Domain/Finance/Services/{StartPayment,SyncPaymentStatus,ReversePayment,PaymeraClient,PaymentAmountCalculator}.php`, `app/Listeners/Finance/{FulfilAcceptedPayment,ReverseWalletCreditOnPaymentReversed}.php`, `app/Events/Finance/*`, `app/Console/Commands/ReconcilePaymeraPayments.php`, `app/Jobs/SyncPaymeraPaymentJob.php`, `config/paymera.php`, `app/Http/Controllers/Api/V1/Member/PaymentController.php`, `app/Http/Requests/Member/StartPaymentRequest.php`, `app/Http/Resources/Finance/PaymentResource.php`, `app/Http/Controllers/Api/V1/Admin/PaymentController.php` |
| Payments table | `database/migrations/2026_09_26_100000_create_payments_table.php`, `app/Domain/Finance/Models/Payment.php`, `app/Domain/Finance/Enums/{PaymentPurpose,PaymentStatus,PaymentMethod}.php`, `database/factories/PaymentFactory.php` |
| Wallet | `app/Domain/Membership/Services/WalletService.php`: `walletFor`, `creditGeneral`, `debitGeneral`, `debit`, `spendOptions` + `buildSpendOption`, `generalBalance`. `InsufficientBalanceException`. |
| Points | `app/Domain/Finance/Services/PointConversionService.php`, `app/Domain/Booking/Services/AmountCalculator.php` |
| Bookings | `app/Domain/Booking/Services/{BookingCreationService::routePayment,BookingBatchCreationService,BookingExtensionService,BookingCancellationService,BookingApprovalService,BookingPresentationService::paymentMethods,SessionClosureService}.php`, `WalletChoiceRequiredException`, `app/Http/Requests/Member/Booking/{StoreBookingRequest,StoreBookingBatchRequest}.php` |
| Plans | `app/Domain/Membership/Services/MembershipActivationService.php` (`activate`), `app/Http/Controllers/Api/V1/Member/MembershipController.php`, `StoreMembershipRequest` |
| Events | `app/Services/Experience/{EventRegistrationService,EventApprovalService}.php`, `app/Domain/Ecosystem/Models/EventAttendee.php`, `app/Support/Enums/EventRegistrationStatus.php`, `app/Http/Controllers/Api/V1/Admin/AdminEventAttendeeController.php` |
| Reception | `app/Http/Controllers/Api/V1/Admin/Reception/{WalletTopUpController,BookingReceptionController,WalkInSessionController}.php`, `SettlePaymentRequest`, `app/Domain/Finance/Services/CashRegisterService.php` |
| Pattern for drivers from config | `app/Providers/OtpServiceProvider.php` |

---

### Task 0: Workspace (controller, done before dispatch)

- [x] Worktree `.claude/worktrees/unified-payments` on `feat/unified-payments` from `feat/add-point-system` at `b438894`, with `vendor/` and `.env` copied from the main checkout.
- [x] Baseline: the full suite on `b438894` gave 17 failed / 1861 passed. The run was on the same tree, in the main checkout, just before this branch; the file is in the controller's scratchpad as `prepush-suite.txt`.
- [ ] Commit this plan and the Arabic design to `docs/superpowers/` (controller).

### Task 1: Generalize the `payments` table

**Files:**
- Create: `database/migrations/2026_10_03_100000_generalize_payments_table.php`
- Modify: `app/Domain/Finance/Models/Payment.php`, `app/Domain/Finance/Enums/PaymentPurpose.php`, `database/factories/PaymentFactory.php`, `app/Http/Resources/Finance/PaymentResource.php`, `app/Http/Requests/Member/StartPaymentRequest.php`, `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
- Test: `tests/Feature/Finance/PaymentsTableTest.php`

**Produces:**
- Columns on `payments`:
  - `method` string(20) default `'paymera'`, indexed; existing rows keep `paymera`.
  - `nullableMorphs('payable')`.
  - `amount_points` unsignedBigInteger nullable; backfilled from `points_to_credit`.
  - `debit_transaction_id` FK `wallet_transactions` nullable, nullOnDelete: the spend.
  - `refund_transaction_id` FK `wallet_transactions` nullable, nullOnDelete, plus `refunded_at` datetime nullable.
  - `collected_via` string(20) nullable (a `PaymentMethod` value: how reception received the money).
  - `received_amount` decimal(14,2) nullable, `received_currency` string(10) nullable.
  - `received_by` FK users nullable, nullOnDelete, plus `received_at` datetime nullable.
  - `expires_at` datetime nullable.
- Made nullable with `->change()`: `terminal_id`, `callback_token` (unique stays), `amount_minor`, `currency`, `currency_exponent`, `lang` — they only make sense for a gateway. `down()` restores NOT NULL.
- `PaymentPurpose` gains `Booking = 'booking'`, `EventRegistration = 'event_registration'`, `CoworkingSession = 'coworking_session'`.
- `Payment`:
  - Casts: `collected_via` → `PaymentMethod`; `received_amount` decimal:2; the datetimes; `amount_points` integer. `method` stays a plain string, because methods are extensible through config.
  - Relations: `payable(): MorphTo`, `debitTransaction()`, `refundTransaction()`, `receivedBy()`.
  - Scopes and helpers: `scopeForPayable(Builder, Model)`, `isRefunded(): bool`.
  - The class docblock is corrected: status is written by `SyncPaymentStatus` for gateway outcomes and by `PaymentService` (from Task 3) for everything else.
- `PaymentResource` adds `method`, `amount_points`, `payable` (`{type, id}` or null) and `expires_at`. Admins also get `collected_via`, `received_amount`, `received_currency`, `received_by`, `received_at`, `refunded_at`.
- `StartPaymentRequest` still accepts only `wallet_top_up` and `plan_purchase` (use `Rule::in([...])` on top of the enum) until Task 7 widens it.
- The guard registers `Payment::class => ['status' => PaymentStatus::class, 'purpose' => PaymentPurpose::class, 'collected_via' => PaymentMethod::class]`. Payment was missing from the guard.

**Tests (named):**
- existing rows read method `paymera` and `amount_points = points_to_credit`;
- a non-gateway row (`method = 'wallet'`, no terminal, token or minor amount) can be created;
- `payable` resolves a Booking via the `booking` alias;
- `PaymentResource` hides admin fields from a member;
- `StartPaymentRequest` still rejects purpose `booking` (422);
- every `tests/Feature/Finance/Paymera*` test still passes.

**Commit:** `feat(payments): one payments table for every method and purpose`

### Task 2: Payment methods as drivers; Paymera behind a gateway interface

**Files:**
- Create:
  - `config/payments.php`
  - `app/Domain/Finance/Payments/Contracts/{PaymentMethodDriver,PaymentGateway}.php`
  - `app/Domain/Finance/Payments/{PaymentMethodRegistry,PaymentStart}.php`
  - `app/Domain/Finance/Payments/Exceptions/UnknownPaymentMethodException.php`
  - `app/Domain/Finance/Payments/Methods/PaymeraGateway.php`
  - `app/Providers/PaymentServiceProvider.php` (register in `bootstrap/providers.php`)
- Modify: `StartPayment`, `SyncPaymentStatus`, `ReversePayment`, `ReconcilePaymeraPayments`, `SyncPaymeraPaymentJob` (only as far as they call the client)
- Test: `tests/Feature/Finance/PaymentMethodRegistryTest.php`

**Produces:**
- **`PaymentMethodDriver`** (interface):
  - `key(): string`
  - `label(): string` — translated through `api.payment.methods.<key>`.
  - `supports(PaymentPurpose $purpose): bool`
  - `start(Payment $payment, array $context = []): PaymentStart`
- **`PaymentGateway extends PaymentMethodDriver`:**
  - `createPayment(Payment $payment, User $payer): CreatedPayment`
  - `fetchStatus(Payment $payment): PaymentStatusResult`
  - `cancel(Payment $payment): void`
  - `callbackRouteName(): string`
- **`PaymentStart`** (final, readonly):
  - `kind` is one of `'done' | 'redirect' | 'pay_at_reception'`.
  - `?string $redirectUrl`.
  - Constructors: `PaymentStart::done()`, `::redirect(string $url)`, `::payAtReception()`.
- **`PaymentMethodRegistry`:**
  - `enabled(): array<string, PaymentMethodDriver>` — enabled in config, in config order.
  - `get(string $key): PaymentMethodDriver` — throws `UnknownPaymentMethodException` for unknown or disabled keys.
  - `gatewayFor(Payment $payment): PaymentGateway` — by `$payment->method`; throws if that method is not a gateway.
  - `availableFor(PaymentPurpose $purpose): array<string, PaymentMethodDriver>`
- **`config/payments.php`:** `['methods' => ['paymera' => ['driver' => PaymeraGateway::class, 'enabled' => env('PAYMENTS_PAYMERA_ENABLED', true)]]]`. Task 4 adds `wallet` and Task 6 adds `cash`. Task 6 also adds `cash_plan_hold_hours` (default 48).
- **`PaymeraGateway`** wraps `PaymeraClient` with no behavior change:
  - `start()` does what `StartPayment` does after writing the row: `createPayment` with `route('paymera.callback'|'paymera.trigger', token)` and the saved-card options. It then stores `payment_id`, or marks the payment `Failed` with `review_reason` and rethrows. It returns `PaymentStart::redirect($url)`.
  - `supports()`: `WalletTopUp` and `PlanPurchase` for now; Task 7 widens it.
- **`StartPayment::handle(...)`** keeps its public signature and return value, because `PaymentController` calls it. It now writes the row with `method = 'paymera'` and `amount_points`, then calls `$registry->get('paymera')->start()`.
- **The rest of the Paymera flow resolves the gateway through the registry instead of type-hinting `PaymeraClient`:** `SyncPaymentStatus::handle/expire`, `ReversePayment`, the reconcile command (which reconciles every pending payment whose method is a gateway) and the job. The lock key becomes `payments:payment:{id}` in both Sync and Reverse. Log lines keep the `paymera` channel for Paymera.

**Tests (named):**
- the registry lists only enabled methods, in config order;
- `get()` of a disabled or unknown key throws;
- **extensibility:** a test-only `FakeGateway` registered with `config(['payments.methods.fake' => [...]])` in `setUp`:
  - is listed;
  - a pending payment with `method = 'fake'` is synced through `FakeGateway::fetchStatus` (not Paymera) and is accepted and credited;
- the full `tests/Feature/Finance` suite passes unchanged.

**Commit:** `refactor(payments): payment methods as drivers, Paymera as the first gateway`

### Task 3: PaymentService, fulfillers, and money-to-points conversion

**Files:**
- Create:
  - `app/Domain/Finance/Services/ReceivedMoneyConverter.php`
  - `app/Domain/Finance/Payments/Contracts/PaymentFulfiller.php`
  - `app/Domain/Finance/Payments/{PaymentService,FulfillerRegistry}.php`
  - `app/Domain/Finance/Payments/Fulfillers/{WalletTopUpFulfiller,PlanPurchaseFulfiller}.php`
  - `app/Domain/Finance/Payments/Exceptions/InsufficientPointsException.php`
- Modify:
  - `app/Listeners/Finance/FulfilAcceptedPayment.php`: becomes a thin wrapper.
  - `app/Http/Controllers/Api/V1/Admin/Reception/WalletTopUpController.php`: uses the converter.
  - `MembershipActivationService::activate`: adds `array $auditProperties = []`, merged into its activity-log properties.
  - `bootstrap/app.php`: renders `InsufficientPointsException` as 422 with the Global Constraints shape.
  - `lang/{en,ar}/api.php`: `payment.insufficient_points`.
- Test: `tests/Unit/Domain/Finance/ReceivedMoneyConverterTest.php`, `tests/Feature/Finance/PaymentServiceTest.php`

**Produces:**
- **`ReceivedMoneyConverter`:**
  - `toPoints(string $amount, string $currencyCode): ConvertedMoney` — a readonly DTO with:
    - `int points`, rounded down;
    - `string baseAmount`;
    - `?string rateToBase`, null for the base currency;
    - `string pointRateUsd`.
    It uses `CurrencyConversionService` and `PointConversionService` in bcmath, and throws `PaymentPricingException::missingExchangeRate` when there is no rate.
  - `pointsToAmount(int $points, string $currencyCode): string` — the money needed to buy `$points`, rounded up to the currency's decimals.
  - `WalletTopUpController::store/preview` switches to it and must give the same results (its existing tests stay green). The float in `paymentInBaseCurrency` goes away.
- **`PaymentFulfiller`:**
  - `purpose(): PaymentPurpose`
  - `pointsDue(Payment $payment): int` — **current** price at settle time; for a plan it is `plan.price_points` re-read.
  - `walletCategory(): WalletTransactionCategory`
  - `describe(Payment $payment): string` — ledger description.
  - `assertPayable(Payment $payment): void` — throws `ReceptionActionException` with a key when the target can no longer be paid.
  - `fulfil(Payment $payment): void`
- **`FulfillerRegistry`** maps purpose → fulfiller. It is registered in `PaymentServiceProvider`; later tasks add entries there.
- **`PaymentService`:**
  - **`settle(Payment $payment, ?Funding $funding = null): Payment`**
    - Idempotent: it locks the payment row and returns early if it is already `Accepted` with `debit_transaction_id` set (or, for a top-up, with `wallet_transaction_id` set).
    - It runs in one DB transaction:
      1. If `$funding` is given, credit `$funding->points` to `payment.wallet` with the audit columns and store `wallet_transaction_id`.
      2. Unless the purpose is a top-up, call `assertPayable`, then debit `pointsDue` from `payment.wallet` in `walletCategory()`. Map `InsufficientBalanceException` to `InsufficientPointsException` with the current balance, the required amount and the shortage. Store `debit_transaction_id` and overwrite `amount_points` with what was actually debited.
      3. `fulfil`.
      4. Set `status = Accepted`.
    - **`Funding`** is a readonly DTO: `int points`, `?PaymentMethod method`, `?string currency`, `?string amount`, `?string rateToBase`, `?string pointRateUsd`, `?int performedBy`.
  - **`fulfilAcceptedGatewayPayment(Payment $payment): void`** — replaces the body of `FulfilAcceptedPayment`:
    - The credit is idempotent on `wallet_transaction_id` and uses today's exact audit values (`PaymentMethod::Paymera`, `minorToCharged`, `appliedExchangeRate`, `point_rate_usd = null`).
    - If spending or fulfilling then throws, the credit stays, the payment becomes `NeedsReview` with `review_reason`, and an alert goes to the `paymera` log channel. This is today's rule, now for every purpose.
- **`PlanPurchaseFulfiller`:**
  - Wallet: the payment's wallet. Its owner is the plan's owner: a user, or a company for a company purchase.
  - `pointsDue` = `plan.price_points`, re-read. A plan that is inactive or missing fails `assertPayable`.
  - `fulfil` = `MembershipActivationService::activate($plan, $ownerType, $ownerId, charge: false, auditAction: <from context: 'membership_purchased'|'membership_purchased_by_card'|'membership_purchased_at_reception'>, auditProperties: ['payment_id' => …])`. It then sets `payments.membership_id`.
  - Category: general.
- **`WalletTopUpFulfiller`** has nothing to spend. `pointsDue` = 0, and `fulfil` is a no-op.

**Tests (named):**
- converter:
  - base currency;
  - SYP with a rate, rounding down for points and up for `pointsToAmount`;
  - a missing rate throws;
- gateway top-up through the service = the same ledger rows and audit values as before (reuse the `PaymeraPaymentTest` scenarios);
- gateway plan purchase:
  - one credit and one debit, net zero points;
  - membership active;
  - `payments.membership_id` set;
- a plan repriced upward between start and acceptance → `NeedsReview`, and the points are kept;
- `settle` twice on the same payment = one credit and one debit;
- reception top-up still records the same audit columns (existing `WalletTopUpControllerTest` stays green).

**Commit(s):** `feat(payments): a single service settles every payment into points` (the converter may be its own commit).

### Task 4: Wallet method, booking and walk-in fulfillers, and paying a new booking

**Files:**
- Create:
  - `app/Domain/Finance/Payments/Methods/WalletMethod.php`
  - `app/Domain/Finance/Payments/Fulfillers/{BookingFulfiller,WalkinSessionFulfiller}.php`
  - migration `2026_10_03_100100_add_price_points_to_sessions.php`
- Modify:
  - `WalletService::buildSpendOption`: `usable_balance` = `max(categoryBalance, generalBalance)`, which is what `debit()` can actually spend, since it falls back to general. Wallets with 0 are still dropped. Update any test that pinned the old value, and say so in the report.
  - `BookingCreationService`, `BookingBatchCreationService`, `SessionClosureService::finalizeClosure`.
  - `StoreBookingRequest`, `StoreBookingBatchRequest`.
  - `app/Domain/Booking/Enums/PaymentSource.php`: adds `Gateway = 'gateway'`.
  - `config/payments.php`: adds `wallet`.
  - `lang`.
- Test: `tests/Feature/Finance/WalletMethodTest.php`, `tests/Feature/Booking/BookingPaymentMethodTest.php`

**Produces:**
- **Migration:** `bookings.price_points` and `walkin_sessions.price_points`, both unsignedInteger nullable — points due.
  - Bookings: written at creation (the final, discounted points) and by the batch.
  - Walk-ins: written by `finalizeClosure` as the charged points (the same `forRange` it prices `amount_owed` from).
  - Bookings that close unpaid get `price_points` rewritten to the recomputed points, alongside the `amount_owed` that closure already rewrites.
- **`WalletMethod`** (key `wallet`):
  - Supports every purpose except `WalletTopUp`.
  - `start($payment, ['wallet_owner_type' => ?, 'wallet_owner_id' => ?])`:
    - Resolves the wallet from `spendOptions($payer, $fulfiller->walletCategory())`. An explicit owner is used if listed, else `ReceptionActionException('api.booking.wallet_not_owned')`. If there is exactly one option it is used. If there are several and none is named, it throws `WalletChoiceRequiredException`. If there are none, it throws `InsufficientPointsException` with current 0.
    - A named or single wallet whose `usable_balance` is below `pointsDue` → `InsufficientPointsException`.
    - Sets `payment.wallet_id`, calls `PaymentService::settle($payment)`, and returns `PaymentStart::done()`.
- **`PaymentService::start(PaymentPurpose $purpose, User $payer, string $method, array $context): array{payment: Payment, start: PaymentStart}`:**
  - `$context` carries `payable` (a Model) or `plan`/`plan_owner`, `amount_minor` for top-ups, and `wallet_owner_*`, `lang`, `audit_action`.
  - Checks `registry->get($method)->supports($purpose)`; otherwise `ReceptionActionException('api.payment.method_not_supported', 422)`.
  - Calls `assertPayable`, creates the Payment row (`method`, `purpose`, `user_id`, `payable`, `plan_id`, `amount_points = pointsDue`, and `wallet_id` = the payer's personal wallet as the default that the driver may replace), then calls the driver.
  - **Wallet and cash rows get a null `callback_token`; only a gateway's `start()` sets one** (Task 7).
- **`BookingFulfiller`:**
  - `pointsDue` = `booking.price_points`, falling back to `AmountCalculator::forRange` for legacy rows.
  - Category `SpaceSpecific`.
  - `assertPayable`: not cancelled or rejected, not already paid.
  - `fulfil`: `payment_state = Paid`; `payment_source` from the payment's method (wallet → `Wallet`, cash → `Cash`, a gateway → `Gateway`); `payment_method` = `collected_via`, or `Paymera` for the paymera gateway, or null for wallet; `paid_at = now()`; `paid_by = payment.received_by`. Plan-discount redemption stays where `BookingCreationService` writes it today — only for a booking that ends up paid at creation.
- **`WalkinSessionFulfiller`:** the same shape.
  - `pointsDue` = `walkin_sessions.price_points` (legacy fallback: `usdToPointsCeil(amount_owed)` through `PointConversionService`).
  - Category `SpaceSpecific`.
  - `assertPayable`: the session is closed, not already paid, and `price_points > 0`.
- **Booking creation:**
  - New optional `payment_method` field on single and batch booking requests: any enabled method key (`Rule::in(array_keys(registry->enabled()))`).
  - **Omitted → today's `routePayment` semantics exactly** (auto-pick a single sufficient wallet, choice exception with several, silently unpaid otherwise). **But when it debits, it now goes through `PaymentService::start(Booking, $member, 'wallet', …)`,** so the debit has a Payment row with `payable` = booking and `wallet_id` = the wallet actually used.
  - `wallet` → pay now or 422 (`InsufficientPointsException` / `WalletChoiceRequiredException`); the booking is not created on failure.
  - `cash` → created confirmed-or-pending as today, Unpaid, and no Payment row.
  - `paymera` → accepted by validation but handled in Task 7. Until then, return 422 `api.payment.method_not_supported`.
  - **Batch:** one Payment (and one debit) **per booking row**, inside the batch transaction — still all-or-nothing. The ledger shows N debits instead of one. This is a ruling, and the docs note it in Task 14.
- Existing booking and batch tests must stay green without edits, except where they asserted the single-debit description or the old `usable_balance`. Name every edited test in the report.

**Tests (named):**
- `wallet` with a single sufficient wallet pays and records a Payment with `wallet_id`;
- `wallet` with insufficient points → 422 with `current`, `required` and `shortage`, and no booking;
- `wallet` with two wallets and none named → the existing `wallet_choice_required` 422;
- company wallet named → debited from the company, `payment.wallet_id` = company wallet;
- `cash` → Unpaid and no payment row;
- omitted → the old behaviour (one sufficient wallet pays; insufficient → Unpaid), and it now has a Payment row;
- batch with `wallet` → N payment rows, all-or-nothing when one window fails;
- a closed walk-in stores `price_points`;
- a wallet with category 5 and general 100 lists `usable_balance` 100.

**Commit(s):** `feat(payments): pay by wallet points through one path, with a strict sufficiency check`

### Task 5: Refunds go back to the wallet that paid; extensions leave a payment row

**Files:** `BookingCancellationService`, `BookingApprovalService::reject`, `BookingExtensionService`, and `PaymentService` (adds `refund(Payment $payment, string $description): void`).
**Test:** `tests/Feature/Booking/BookingRefundToPayingWalletTest.php`

**Produces:**
- **`PaymentService::refund`:**
  - Locks the payment; it is idempotent on `refunded_at`.
  - Credits `amount_points` (general, `WalletTransactionSource::Refund`) to **`payment.wallet`**, then stores `refund_transaction_id` and `refunded_at`.
  - Only `Accepted` payments with a `debit_transaction_id` are refundable.
- **Cancel and reject** refund every accepted, unrefunded payment whose payable is the booking. **A booking with no payment rows** (paid by wallet before this feature) keeps today's fallback exactly: recompute the price minus the redemption, credit the personal wallet. Voiding the redemption is unchanged.
- **Extension:** when it debits the difference today (personal wallet only), it now goes through `PaymentService::start(Booking, member, 'wallet', ['wallet_owner_type' => 'user', 'wallet_owner_id' => member id])` with `amount_points` = the difference. That leaves a Payment row, so a later cancellation refunds original plus extension. **The flip-to-Unpaid behaviour on insufficient balance is unchanged** (a follow-up, not this task). The Payment row needs `pointsDue` = the difference: pass it through `$context['points']`, which `BookingFulfiller::pointsDue` honours when present, and have the extension fulfil path **not** re-mark state. Use a context flag `['extension' => true]` read by `BookingFulfiller::fulfil`.

**Tests (named):**
- booking paid from a company wallet, then cancelled → the company wallet is credited, not the personal one;
- rejection of a pending paid booking → refunded to the paying wallet;
- cancel twice → one refund;
- extension paid by wallet, then cancel → original plus extension refunded;
- a legacy booking with no payment rows → today's refund exactly.

**Commit:** `fix(bookings): refund to the wallet that paid, from the recorded payments`

### Task 6: Cash — pay at reception; a plan bought with cash waits for reception

**Files:**
- Create:
  - `app/Domain/Finance/Payments/Methods/CashMethod.php`
  - `app/Console/Commands/ExpirePendingCashPayments.php` (schedule it hourly in `routes/console.php`)
- Modify:
  - `config/payments.php`: adds `cash`, plus `'cash_plan_hold_hours' => (int) env('PAYMENTS_CASH_PLAN_HOLD_HOURS', 48)`.
  - `Member/MembershipController::store`, `StoreMembershipRequest`, `lang`.
- Test: `tests/Feature/Finance/CashMethodTest.php`, `tests/Feature/Membership/PlanPurchasePaymentMethodTest.php`

**Produces:**
- **`CashMethod`** (key `cash`):
  - Supports `PlanPurchase`, `Booking`, `EventRegistration` and `CoworkingSession` — **not** `WalletTopUp`. A cash top-up is reception-only, through the existing endpoint.
  - `start()`:
    - For a plan purchase: leave the payment `Pending` with `expires_at = now() + cash_plan_hold_hours` and return `PaymentStart::payAtReception()`.
    - For the other purposes: `PaymentService::start` must **not** create a row for cash, because the payable simply stays unpaid and reception creates the row when it settles. Implement this as `CashMethod::createsPaymentRowFor(PaymentPurpose): bool`, or a `start()` that deletes nothing — pick one and document it. The result is still `payAtReception()`.
- **`POST member/memberships`** gains an optional `payment_method` (`wallet` default | `cash` | `paymera`):
  - **`wallet`:** today's path, but through `PaymentService::start(PlanPurchase, …, 'wallet', ['plan' => $plan, 'plan_owner' => [type, id], 'audit_action' => 'membership_purchased'])`. The response is unchanged (the membership, 201). The 422s for insufficient points and a full seat pool keep their current keys. Company purchase (`company_id` + `manageMembers`) keeps working, and the company wallet pays.
  - **`cash`:** 201 `{"data": {"pending_purchase": PaymentResource}}`. There is no membership yet. A second cash request for the same owner and plan while one is pending returns the existing one.
  - **`paymera`:** delegates to the existing card plan-purchase start and returns the start-payment response shape.
- **`GET member/memberships/current`** gains `pending_purchase` (the latest pending cash plan payment for the member or their company purchase, else null) without changing existing keys.
- **`ExpirePendingCashPayments`** (`payments:expire-pending`):
  - Sets `status = Expired` on pending **cash** payments whose `expires_at` has passed.
  - Never touches gateway payments, which `paymera:reconcile` owns.
  - Carries on past a single failure.

**Tests (named):**
- cash plan → pending payment, no membership, `expires_at` = now + 48 h;
- a second cash request returns the same pending payment;
- expiry command → `expired`, no membership, and gateway payments untouched;
- wallet plan still returns the membership and now has a Payment row;
- company purchase by wallet debits the company;
- `paymera` plan returns `redirect_url`;
- `memberships/current` shows `pending_purchase`.

**Commit:** `feat(payments): cash at reception, and plans bought with cash wait for it`

### Task 7: Paymera for bookings, event registrations and sessions; "pay now" for anything unpaid

**Files:**
- `PaymeraGateway::supports` (all purposes).
- `PaymentAmountCalculator`: `forPoints(int $points): array{0: int, 1: int}`, the same ceil rule as `forPlan`.
- `StartPaymentRequest`, `Member/PaymentController::store`.
- `BookingCreationService`/batch: the `paymera` inline branch.
- `lang`.

**Test:** `tests/Feature/Finance/PayNowTest.php`

**Produces:**
- **`POST member/payments`:**
  - **Request:**
    - `purpose`: any `PaymentPurpose`.
    - `method`: nullable, default `paymera`; must be enabled and support the purpose.
    - `payable_id`: required for `booking`, `event_registration` and `coworking_session`. It must belong to the caller — booking and walk-in by `user_id`, attendee by `user_id` — else 404.
    - `wallet_owner_type` / `wallet_owner_id` for `wallet`.
    - Unchanged: `plan_id`, `amount`, `lang`.
  - **Response:** the start-payment shape. For the old request (no `method`, top-up or plan) the body keeps `data.payment` and `data.redirect_url` exactly, with `next` added.
  - `payable_id` resolves to a Booking, WalkinSession or EventAttendee.
  - An already-paid target → 409 `api.payment.already_paid`.
  - Not payable yet → the fulfiller's key:
    - a walk-in still open;
    - a booking cancelled or rejected;
    - an event registration that is pending approval or rejected.
- **Gateway start for a payable:**
  - `points_to_credit = amount_points = pointsDue`.
  - `amount_minor` comes from `forPoints`.
  - `payable` is set.
  - `wallet_id` = the payer's personal wallet, because money always lands in the payer's own wallet first.
- On acceptance, `fulfilAcceptedGatewayPayment` credits, then spends and fulfils.
  - **If the payable was paid another way in the meantime,** `assertPayable` throws inside the spend step, so the points stay in the wallet and the payment becomes `NeedsReview` with `review_reason = 'payable already settled — points kept in wallet'`. This is a ruling: visible, and nothing is lost.
- **Booking creation with `payment_method = paymera`:** the booking is created Unpaid (it holds its slot like any unpaid booking), then a paymera payment for it is started. The response is the booking resource plus `payment` and `redirect_url` beside `data`. On a gateway start failure (502/503), the booking still exists, Unpaid, and the response says so with a 201 and `payment: null` plus a `payment_error` message key. It is never rolled back, because the member can pay at reception.

**Tests (named):**
- the old top-up request → same body, now with `next`;
- pay-now wallet on an unpaid booking → paid, 201 `next: done`;
- pay-now paymera on an unpaid booking → `redirect_url`; accept → booking Paid with `payment_source = gateway`; fail → still Unpaid;
- pay-now on someone else's booking → 404;
- on a paid booking → 409;
- pay-now on an open walk-in → 422, and on a closed unpaid one → paid;
- paymera accepted after the booking was paid at the desk → `NeedsReview`, points in the wallet;
- booking created with `paymera` → booking Unpaid plus `redirect_url`.

**Commit(s):** `feat(payments): pay any unpaid booking, session or registration from the app`

### Task 8: Events — pay by any method, and no entry until paid

**Files:**
- Migration `2026_10_03_100200_add_payment_to_event_attendees.php`.
- `EventAttendee` (Ecosystem — **no Finance/Membership imports**; the columns and casts use `App\Support\Enums` or plain strings).
- `EventRegistrationService`, `EventApprovalService`.
- New `app/Domain/Finance/Payments/Fulfillers/EventRegistrationFulfiller.php` (Finance may import Ecosystem).
- The member register request (find it via `EventController::register`).
- `AdminEventAttendeeController` + route `POST admin/events/{event}/attendees/{attendee}/check-in`.
- `EventAttendeeResource`.
- `EventBrowseService` registration block.
- `lang`.

**Tests:** `tests/Feature/Experience/EventPaymentTest.php`, `tests/Feature/Experience/EventCheckInTest.php`

**Produces:**
- **Columns on `event_attendees`:**
  - `price_points` unsignedInteger nullable (snapshot at registration; null for free events);
  - `payment_state` string(10) nullable (`paid` | `unpaid`; null for free) — cast to an `App\Support\Enums\EventPaymentState` enum, registered in the guard;
  - `payment_method` string(20) nullable (the member's choice);
  - `payment_wallet_owner_type` string(20) nullable, `payment_wallet_owner_id` unsignedBigInteger nullable;
  - `paid_at` datetime nullable;
  - `checked_in_at` datetime nullable;
  - `checked_in_by` FK users nullable, nullOnDelete.
- **Registration:** optional `payment_method` (default `wallet`) and `wallet_owner_type/id`. For a paid event (`!is_free && price_points > 0`, the existing rule):
  - **No approval needed:**
    - `wallet` → paid now through `PaymentService` (the wallet choice works like bookings, company wallets included). **Insufficient → the existing 422 `points` validation error, with the same `current`/`required`/`shortage` shape.**
    - `cash` → Approved + `unpaid`.
    - `paymera` → Approved + `unpaid`, then start a paymera payment for it; the response adds `payment` and `redirect_url`.
  - **Approval needed:** Pending, with the choice stored.
    - At **approval**, `wallet` → charge the stored wallet choice (null = today's personal wallet) through `PaymentService`; insufficient → the existing `approve_insufficient_points` 422 and no approval.
    - `cash`/`paymera` → Approved + `unpaid`. The member pays later with pay-now (Task 7), or at reception.
  - `EventRegistrationFulfiller`:
    - `pointsDue` = `attendee.price_points`; category General.
    - `assertPayable`: approved, not paid, and the event is not cancelled.
    - `fulfil`: `payment_state = paid`, `paid_at`.
  - The `events.skip_balance_check` config bypass keeps working for the `wallet` path.
  - Registration is now audit-logged (`event_registered`, with `price_points` and `payment_method`).
- **Check-in** `POST admin/events/{event}/attendees/{attendee}/check-in` (admin|operations):
  - 404 unless the attendee belongs to the event.
  - 409 `api.event.check_in_not_approved` unless Approved.
  - **409 `api.event.check_in_unpaid` when the event is paid and `payment_state = unpaid`.**
  - 409 `api.event.already_checked_in`.
  - Otherwise: `attended = true`, `checked_in_at`, `checked_in_by`, an audit log, and 200 `{message}`.
- `EventAttendeeResource` and the member's registration block show `payment: {state, method, price_points, price_label, paid_at}`.

**Tests (named):**
- wallet pays now;
- company wallet works;
- insufficient → 422 in the old shape;
- cash → approved, unpaid;
- paymera → `redirect_url`, then accept → paid;
- approval-required + wallet charges at approval from the stored wallet;
- approval-required + cash → approved unpaid;
- check-in:
  - unpaid → 409;
  - paid → 200;
  - free event → 200;
  - not approved → 409;
  - twice → 409;
  - another event's attendee → 404;
- `DomainLayerBoundaryTest` still passes.

**Commit(s):** `feat(events): pay a registration by any method; no check-in until paid`

### Task 9: Reception — settle anything, see what is outstanding

**Files:**
- Create:
  - `app/Http/Controllers/Api/V1/Admin/Reception/ReceptionPaymentController.php`
  - `app/Http/Requests/Admin/Reception/SettleAtReceptionRequest.php`
- Modify:
  - `PaymentService`: adds `settleAtReception(...)`.
  - `BookingReceptionController::settlePayment` and `WalkInSessionController::settlePayment`: delegate.
  - `WalletTopUpController::store`: also writes a Payment row.
  - `Admin/PaymentController::index`: `method` and `purpose` filters.
  - `routes/api/v1/admin.php`, `lang`.
- Test: `tests/Feature/Finance/ReceptionSettlementTest.php`, `tests/Feature/Finance/OutstandingTest.php`

**Produces:**
- **`POST admin/reception/payments/settle`:**
  - `purpose` ∈ {booking, coworking_session, event_registration, plan_purchase}.
  - `payable_id` for the first three; `payment_id` of a pending cash plan purchase for the fourth.
  - `collected_via` (`PaymentMethod`, required).
  - `amount` (decimal string, optional → exactly the amount due).
  - `currency` (optional → the base currency; must exist and have a rate).
- **`PaymentService::settleAtReception(PaymentPurpose $purpose, Model|Payment $target, PaymentMethod $collectedVia, ?string $amount, ?string $currency, User $operator): SettledAtReception`**. In one transaction:
  1. Resolve the fulfiller and `pointsDue`. Already paid → 409 `api.payment.already_paid`.
  2. `received` = `ReceivedMoneyConverter::toPoints(amount ?? pointsToAmount(due, currency), currency)`. If `received.points < due` → 422 `api.payment.amount_short`, with `shortage` = `pointsToAmount(due - received.points, currency)` and `currency`.
  3. Create the Payment, or reuse the pending plan payment: `method = 'cash'`, `collected_via`, `received_*`, `received_by`, `received_at`, and `wallet_id`:
     - the payable's owner's personal wallet;
     - for a plan, the plan owner's wallet (a company for a company purchase).
  4. `settle($payment, Funding(points: received.points, method: collected_via, currency, amount, rate, pointRateUsd, performedBy: operator))`. This credits everything received and debits what is due — **the overpayment stays as points in that wallet**.
  5. For a plan: `fulfil` activates with `auditAction 'membership_purchased_at_reception'`. If activation throws (a full seat pool), the whole transaction rolls back and the endpoint returns 422 — nothing is recorded.
  6. Response 201 `{"data": {"payment": PaymentResource, "points_credited": int, "points_charged": int, "change_points": int}}`.
- **Old endpoints** `POST reception/bookings/{booking}/settle-payment` and `POST reception/walk-ins/{walkinSession}/settle-payment`:
  - Same request, response, preconditions (checked out, amount owed) and error keys as today.
  - They now call `settleAtReception` with `amount = null`, so they write a ledger row and a Payment row.
  - Existing `SessionClosureServiceTest` and reception tests stay green.
- **`GET admin/reception/outstanding?user_id=`** returns everything unpaid for the member:
  - bookings not cancelled or rejected, with `price_points > 0` and Unpaid;
  - closed unpaid walk-ins;
  - approved unpaid paid-event registrations;
  - pending, unexpired cash plan purchases, as the payer or for a company they buy for.

  Each item is `{purpose, payable_id|payment_id, title, starts_at|null, amount_points, amount: {value, currency}}`, with `total_points` and a total `amount`, in the base currency or `?currency=`.
- **Reception top-up** also writes a Payment row: purpose `wallet_top_up`, method `cash`, `collected_via`, Accepted, `wallet_transaction_id`, `received_*`. The response is unchanged.
- **`GET admin/payments`:** new `method` and `purpose` filters.

**Tests (named):**
- exact cash settle of a booking → paid, with a Payment row and a ledger credit plus debit;
- overpay in SYP → paid, and the difference is left as points;
- underpay → 422 with the shortage;
- settle a pending cash plan → membership active;
- plan with a full seat pool → 422, and nothing recorded;
- event registration settled, then check-in 200;
- old settle endpoints → same responses as before, and now a Payment row;
- `outstanding` lists exactly the unpaid items across all four kinds;
- a top-up creates a Payment row;
- the admin filters work.

**Commit(s):** `feat(reception): settle any operation with the amount received; list what a member owes`

### Task 10: What the app shows — methods per operation, and payment state everywhere

**Files:**
- Create:
  - `app/Http/Controllers/Api/V1/Member/PaymentMethodsController.php`
  - `app/Support/Presenters/PaymentStatePresenter.php`
- Modify:
  - The member booking presentation (list and detail).
  - `CoworkingSessionResource`, `SessionReportBuilder` totals: add `payment`.
  - `EventBrowseService` (member registration block), memberships current.
  - `routes/api/v1/member.php`, `lang`.
- Test: `tests/Feature/Finance/MemberPaymentMethodsTest.php`, `tests/Feature/Finance/PaymentStateBlockTest.php`

**Produces:**
- **`GET member/payment-methods`:**
  - Query: `purpose` (required), `payable_id` | `plan_id` | `amount`.
  - Response: `{"data": {"amount_points": int|null, "price": string|null, "price_point": string|null, "methods": [ … ]}}`.
  - Each method is `{key, label, available: bool, reason: string|null}`. The `wallet` method adds `wallets: [{owner_type, owner_id, owner_label, usable_balance, sufficient}]`.
  - `price` and `price_point` come from `PriceLabelPresenter`.
  - The order comes from config. Disabled methods are absent. Any gateway added through config appears here with no other change.
- **`PaymentStatePresenter::for(Model $payable): array`** returns `{state: 'paid'|'unpaid'|'pending'|null, method: string|null, amount_points: int|null, price: string|null, price_point: string|null, paid_at: string|null, payment_id: int|null}`. `null` state = nothing to pay (free, or fully covered by the plan).
- The presenter is added as a `payment` key — never by renaming or removing an existing key — to:
  - member bookings index and show;
  - the member event registration block (my-events and detail);
  - `coworking-sessions/current` and the session report;
  - `memberships/current` for a `pending_purchase`.

**Tests (named):**
- methods for a booking list wallet (with sufficiency), cash and paymera;
- a disabled paymera is absent;
- a top-up lists paymera only;
- a test-registered fake gateway appears;
- the payment block reads `unpaid` then `paid` on a booking, `null` on a free event, and `pending` for a cash plan.

**Commit:** `feat(payments): methods per operation and a payment block on every payable`

### Task 11: The cash register counts money, including settlements

**Files:** `app/Domain/Finance/Services/CashRegisterService.php`, and its test.

**Produces:**
- `closeSession` sums **`payment_amount`** (money) in the session's currency — read how the session stores its currency first. It no longer sums `amount` (points).
- It counts ledger credits with `performed_by_user_id` = the operator and `payment_method = cash` during the session. Task 9's settlements are therefore now included automatically.
- Rows in another currency are reported separately, or converted with the stored `exchange_rate_to_base` — follow what the session model supports, and say which in the report.

**Tests (named):**
- a cash top-up of 150,000 SYP → the expected cash is 150,000, not the points count;
- a cash booking settlement is counted;
- a Sham/MTN settlement is not counted as cash.

**Commit:** `fix(finance): the cash register counts money received, including settlements`

### Task 12: Notifications

**Files:** `lang/{en,ar}/notifications.php`, plus the call sites in `PaymentService`, `ExpirePendingCashPayments` and `EventRegistrationService`/`EventApprovalService`.

**Produces:** these templates, sent through `NotificationService::sendToUser` **after commit** (`DB::afterCommit`):
- `payment.received` (`title` / `body` with `:points` and `:purpose`) — to the payer when a gateway or reception payment is accepted. Not for a wallet payment the member just made in the app.
- `payment.expired` — when a pending cash plan purchase expires.
- `event.payment_due` — when a paid registration is approved (or registered without approval) and is unpaid.

Data payload: `payment_id`, `purpose`, `payable_type`, `payable_id`.

**Tests:** `Notification::fake()`, one per template, plus "no notification for an in-app wallet payment".

**Commit:** `feat(payments): tell members when a payment lands, a hold expires, or an event is unpaid`

### Task 13: Documentation

- `docs/decisions/unified-payments.md` in the house style of `docs/decisions/coworking-self-service-sessions.md`, covering:
  - the model (points ledger, one `payments` table, drivers, fulfillers);
  - the method-by-operation table;
  - how to add a gateway (a driver class, a config entry, `supports()`; nothing else);
  - the backward-compatibility list;
  - the rulings (per-booking payment rows in a batch; `NeedsReview` when a payable was settled elsewhere; refunds as points to the paying wallet; the 48-hour hold);
  - "Explicitly not done here": the booking-extension flip to Unpaid, refunds for cancelled paid events, card refunds to the card, café orders;
  - the guard and test inventory.
- `docs/paymera-mobile-api.md` (**Arabic**): the new purposes, `method`, `payable_id`, `next`, `payment-methods`, and the payment block.
- `docs/decisions/README.md` index line. `CLAUDE.md`: a short paragraph on payments (drivers + fulfillers + config) after the auth section.

**Commit:** `docs(payments): unified payments decision record and app guide`

### Task 14: Verification (controller)

- The full suite against the baseline: no new failures.
- `./vendor/bin/pint --test`.
- `php artisan route:list --path=payment`, and likewise for `outstanding` and `check-in`.
- Postman: a "Payments" folder for member and reception, captured from real responses the way the coworking folder was built. Done by the controller after the final review.
