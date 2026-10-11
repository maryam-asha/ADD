# One Way to Pay — Implementation Plan (Phase 1 of 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every member operation pays through its own endpoint — a booking or event registration by sending its create request again with its id, a coworking session and a top-up through their own endpoints — and `POST member/payments` is removed.

**Architecture:** The body of today's `PaymentController::store` (coupon on an owed record, then `PaymentService::start`) moves into one domain service, `PayableCheckout`, called by purpose. A controller trait, `AnswersPayments`, maps its outcome and its failures to JSON once. `BookingController::store` and `EventController::register` gain an id branch that skips every creation rule and only pays the existing record; coworking sessions and top-ups get small endpoints of their own. Every test that called `POST member/payments` moves to the operation's endpoint keeping its assertion, then the route is deleted.

**Tech Stack:** Laravel 12, PHP 8.3, PHPUnit feature tests on in-memory SQLite, Laravel Pint.

**Spec:** `docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md` (this plan is its "Delivery → 1. One way to pay"; Phase 2 is `docs/superpowers/plans/2026-10-06-prepayment-all-or-nothing.md`).

## Global Constraints

- Run PHP as `/c/laragon/bin/php/php-8.3.33-nts-Win32-vs16-x64/php.exe` (the `php` on PATH is 8.2 and the lock file needs ≥ 8.3). Below, `PHP` means that path.
- Tests: `PHP artisan test <path>`; style: `PHP vendor/bin/pint --test <files>` must pass for every touched PHP file.
- The full suite has **17 pre-existing failures** (audit-log `'1'` vs `1`, the EventAttendee enum guard, notifications on SQLite). A task is green when it adds none: compare against that list, never assume.
- Every update-style response is unchanged by this plan; new payment responses follow the shapes below exactly.
- Copy/keys go in `lang/en/api.php` **and** `lang/ar/api.php`, inside the existing group named; never inline a string.
- Do not read or search `vendor/`, `storage/logs`, `storage/framework`, `bootstrap/cache`.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

**Response shapes (exact):**

| endpoint | status | body |
|---|---|---|
| `POST member/bookings` with `booking_id` | 200 | `{"data": BookingResource, "payment": PaymentResource\|null, "redirect_url": string\|null, "next": "done"\|"redirect"\|"pay_at_reception", "coupon"?: {...}}` |
| `POST member/events/{id}/register` with `ticket_id` | 200 | `{"data": {"ticket_id", "status", "payment", "redirect_url", "next", "coupon"?}}` |
| `POST member/coworking-sessions/{type}/{id}/pay` | 201 | `{"data": {"payment", "redirect_url", "next"}, "coupon"?: {...}}` (the shape `POST member/payments` answered with) |
| `POST member/wallet/top-ups` | 201 | `{"data": {"payment", "redirect_url", "next": "redirect"}}` (same) |

## Review Focus

1. **A request sent again with an id minutes before the start** must not be refused as "too soon" / "start in the past" — the id branch runs before every creation rule (Task 2 pins it).
2. **The app re-sends the coupon code it sent at creation** — the same code on a record that already carries it is ignored, not `already_applied` (Task 1 pins it).
3. **Another member's id** → 404, never a payment on someone else's booking or registration (Tasks 2, 3, 4 pin it).
4. **Cash top-up from the app** → 422 on `payment_method`, nothing written, while a reception cash top-up still works (Task 5 pins it).
5. **Event registration form on the id path** — a form-backed event must accept `ticket_id` without the form answers (Task 3 pins it).

---

### Task 0: Drop the unreleased payment-purposes work

The worktree holds an uncommitted `GET member/payment-purposes` built as a catalog for `POST member/payments`, which this plan removes. Keep only its wording fix to `api.payment.booking_not_payable`.

**Files:**
- Restore to HEAD: `app/Domain/Finance/Enums/PaymentPurpose.php`, `app/Http/Controllers/Api/V1/Member/PaymentMethodsController.php`, `app/Http/Requests/Member/StartPaymentRequest.php`, `docs/paymera-mobile-api.md`, `postman/ADD-OS.postman_collection.json`, `routes/api/v1/member.php`, `lang/en/api.php`, `lang/ar/api.php`
- Delete: `app/Http/Controllers/Api/V1/Member/PaymentPurposesController.php`, `tests/Feature/Finance/MemberPaymentPurposesTest.php`

- [ ] **Step 1: Restore and delete**

```bash
git checkout HEAD -- app/Domain/Finance/Enums/PaymentPurpose.php app/Http/Controllers/Api/V1/Member/PaymentMethodsController.php app/Http/Requests/Member/StartPaymentRequest.php docs/paymera-mobile-api.md postman/ADD-OS.postman_collection.json routes/api/v1/member.php lang/en/api.php lang/ar/api.php
rm app/Http/Controllers/Api/V1/Member/PaymentPurposesController.php tests/Feature/Finance/MemberPaymentPurposesTest.php
git status --short
```
Expected: `git status --short` prints nothing.

- [ ] **Step 2: Re-apply the wording fix**

In `lang/en/api.php`, group `payment`:
```php
        'booking_not_payable' => 'A cancelled, rejected or not-attended booking cannot be paid.',
```
In `lang/ar/api.php`, group `payment`:
```php
        'booking_not_payable' => 'لا يمكن دفع حجز ملغى أو مرفوض أو لم يتم الحضور إليه.',
```

- [ ] **Step 3: Run the tests that read this key**

Run: `PHP artisan test tests/Feature/Booking/BookingOutcomesTest.php`
Expected: PASS (the test asserts the key, not the wording).

- [ ] **Step 4: Commit**

```bash
git add lang/en/api.php lang/ar/api.php
git commit -m "fix(payments): the not-payable message names a not-attended booking too

A no-show is refused by the same check as a cancelled or rejected booking,
so the message lists all three.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 1: `PayableCheckout` and `AnswersPayments` — the shared code, called by purpose

**Files:**
- Create: `app/Domain/Finance/Payments/CheckoutOutcome.php`
- Create: `app/Domain/Finance/Payments/PayableCheckout.php`
- Create: `app/Http/Controllers/Concerns/AnswersPayments.php`
- Modify: `app/Http/Controllers/Api/V1/Member/PaymentController.php` (store's payable branch uses `PayableCheckout`; `use RespondsToGatewayFailures;` → `use AnswersPayments;`)
- Test: `tests/Feature/Finance/PayableCheckoutTest.php`

**Interfaces:**
- Produces:
  - `final readonly class CheckoutOutcome { public function __construct(public ?Payment $payment, public PaymentStart $start, public ?array $coupon = null) }`
  - `PayableCheckout::pay(PaymentPurpose $purpose, Model $payable, User $member, string $method, array $context = [], ?string $couponCode = null): CheckoutOutcome` — `$context` keys: `wallet_owner_type` (`OwnerType|string|null`), `wallet_owner_id` (`?int`). Throws (uncaught): `ReceptionActionException`, `WalletChoiceRequiredException`, `InsufficientPointsException`, `CouponRejectedException`, `UnknownPaymentMethodException`, gateway exceptions.
  - trait `AnswersPayments` (includes `RespondsToGatewayFailures`):
    - `protected function checkoutFields(CheckoutOutcome $outcome): array` → `['payment' => PaymentResource|null, 'redirect_url' => ?string, 'next' => string]`
    - `protected function paymentFailureResponse(Throwable $e, int $userId): ?JsonResponse`
    - `protected function payBesideNewRecord(PayableCheckout $checkout, PaymentPurpose $purpose, Model $record, User $member, string $method): array` → `['payment' => PaymentResource|null, 'redirect_url' => ?string]` plus `'payment_error' => string` when it could not open

- [ ] **Step 1: Write the failing test**

`tests/Feature/Finance/PayableCheckoutTest.php`:

```php
<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\PayableCheckout;
use App\Domain\Finance\Payments\PaymentStart;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Pricing\Exceptions\CouponRejectedException;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponApplication;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one place an existing booking, session or registration is paid by a
 * chosen method — what POST member/payments did, now called by purpose from
 * each operation's own endpoint
 * (docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md).
 */
class PayableCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(int $points = 0): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        return $member;
    }

    private function unpaidBooking(User $member): Booking
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 100, 'slot_granularity_minutes' => 60]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 11:00', 'Asia/Damascus')->utc(),
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => null,
            'price_points' => 100,
        ]);
    }

    public function test_the_wallet_pays_now(): void
    {
        $member = $this->member(300);
        $booking = $this->unpaidBooking($member);

        $outcome = app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $booking, $member, 'wallet');

        $this->assertSame(PaymentStart::DONE, $outcome->start->kind);
        $this->assertNotNull($outcome->payment);
        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);
    }

    public function test_cash_sends_the_member_to_reception_and_writes_nothing(): void
    {
        $member = $this->member();
        $booking = $this->unpaidBooking($member);

        $outcome = app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $booking, $member, 'cash');

        $this->assertSame(PaymentStart::PAY_AT_RECEPTION, $outcome->start->kind);
        $this->assertNull($outcome->payment);
        $this->assertSame(PaymentState::Unpaid, $booking->refresh()->payment_state);
    }

    /**
     * The app re-sends, with the id, the code it sent when it made the
     * booking. The booking already carries it: nothing to apply, no refusal.
     */
    public function test_the_code_a_record_already_carries_is_ignored(): void
    {
        $member = $this->member(300);
        $booking = $this->unpaidBooking($member);
        $coupon = Coupon::factory()->amountOff(10)->create(['code' => 'TENOFF']);
        app(CouponApplication::class)->apply($booking, $member, $coupon);

        $outcome = app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $booking, $member, 'wallet', [], 'TENOFF');

        $this->assertSame(PaymentStart::DONE, $outcome->start->kind);
        $this->assertSame(210, app(WalletService::class)->generalBalance(
            Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->firstOrFail()
        ));
    }

    public function test_a_different_code_on_a_record_that_has_one_is_refused(): void
    {
        $member = $this->member(300);
        $booking = $this->unpaidBooking($member);
        $first = Coupon::factory()->amountOff(10)->create(['code' => 'TENOFF']);
        Coupon::factory()->amountOff(5)->create(['code' => 'OTHER']);
        app(CouponApplication::class)->apply($booking, $member, $first);

        $this->expectException(CouponRejectedException::class);

        app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $booking, $member, 'wallet', [], 'OTHER');
    }
}
```

`CouponFactory::amountOff(int $points)` is the factory's own "points off" state.

- [ ] **Step 2: Run test to verify it fails**

Run: `PHP artisan test tests/Feature/Finance/PayableCheckoutTest.php`
Expected: FAIL — `Class "App\Domain\Finance\Payments\PayableCheckout" not found`.

- [ ] **Step 3: Write `CheckoutOutcome`**

`app/Domain/Finance/Payments/CheckoutOutcome.php`:

```php
<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Finance\Models\Payment;

/**
 * What paying an existing record came to: the payment row (null for cash,
 * which writes none, or when a coupon left nothing to pay), what the app
 * does next, and — when a code was sent — what it took off
 * (CouponOutcomePresenter).
 */
final readonly class CheckoutOutcome
{
    /**
     * @param  array<string, mixed>|null  $coupon
     */
    public function __construct(
        public ?Payment $payment,
        public PaymentStart $start,
        public ?array $coupon = null,
    ) {}
}
```

- [ ] **Step 4: Write `PayableCheckout`**

`app/Domain/Finance/Payments/PayableCheckout.php`:

```php
<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Services\CouponApplication;
use App\Domain\Pricing\Services\CouponEligibility;
use App\Domain\Pricing\Services\CouponLedger;
use App\Support\Presenters\CouponOutcomePresenter;
use Illuminate\Database\Eloquent\Model;

/**
 * Pays something that already exists — a booking, a closed coworking
 * session, an event registration — by the method the member chose, through
 * the one payment path (PaymentService). Each operation's own endpoint calls
 * it with its purpose, so the rule lives once
 * (docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md).
 *
 * A coupon code is applied to what the record owes before the payment
 * opens (CouponApplication), as pay now always did. The code the record
 * already carries is ignored rather than refused: the app re-sends, with the
 * id, the request it made the record with — coupon included.
 *
 * Failures are not caught here: the endpoint decides how each one reads.
 */
class PayableCheckout
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly CouponEligibility $coupons,
        private readonly CouponLedger $ledger,
        private readonly CouponApplication $application,
        private readonly CouponOutcomePresenter $outcomes,
    ) {}

    /**
     * @param  array{wallet_owner_type?: mixed, wallet_owner_id?: int|null}  $context
     */
    public function pay(
        PaymentPurpose $purpose,
        Model $payable,
        User $member,
        string $method,
        array $context = [],
        ?string $couponCode = null,
    ): CheckoutOutcome {
        $coupon = $couponCode !== null ? $this->coupons->resolve($couponCode) : null;

        if ($coupon !== null && $this->ledger->forRedeemable($payable)?->coupon_id === $coupon->id) {
            $coupon = null;
        }

        $outcome = null;

        if ($coupon !== null) {
            $quote = $this->application->apply($payable, $member, $coupon);
            $outcome = $this->outcomes->fromQuote($couponCode, $quote);

            if ($quote->finalPoints === 0) {
                return new CheckoutOutcome(null, PaymentStart::done(), $outcome);
            }
        }

        $locale = app()->getLocale();

        ['payment' => $payment, 'start' => $start] = $this->payments->start($purpose, $member, $method, array_filter([
            'payable' => $payable,
            'wallet_owner_type' => $context['wallet_owner_type'] ?? null,
            'wallet_owner_id' => $context['wallet_owner_id'] ?? null,
            // The gateway page speaks the member's language.
            'lang' => in_array($locale, ['ar', 'en'], true) ? $locale : null,
        ], fn ($value) => $value !== null));

        return new CheckoutOutcome($payment, $start, $outcome);
    }
}
```

- [ ] **Step 5: Write the `AnswersPayments` trait**

`app/Http/Controllers/Concerns/AnswersPayments.php`:

```php
<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Exceptions\WalletChoiceRequiredException;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\CheckoutOutcome;
use App\Domain\Finance\Payments\Exceptions\UnknownPaymentMethodException;
use App\Domain\Finance\Payments\PayableCheckout;
use App\Domain\Identity\Models\User;
use App\Http\Resources\Finance\PaymentResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * How every member endpoint that pays answers — once, for bookings,
 * registrations, coworking sessions and top-ups alike. InsufficientPoints
 * and a refused coupon are left to their global renderers
 * (bootstrap/app.php).
 */
trait AnswersPayments
{
    use RespondsToGatewayFailures;

    /**
     * @return array{payment: PaymentResource|null, redirect_url: string|null, next: string}
     */
    protected function checkoutFields(CheckoutOutcome $outcome): array
    {
        return [
            'payment' => $outcome->payment === null ? null : new PaymentResource($outcome->payment),
            // What the app loads in its WebView, when there is one.
            'redirect_url' => $outcome->start->redirectUrl,
            'next' => $outcome->start->kind,
        ];
    }

    /**
     * The answer for a payment that could not be taken; null when `$e` is
     * not one this maps (the caller rethrows).
     */
    protected function paymentFailureResponse(Throwable $e, int $userId): ?JsonResponse
    {
        if ($e instanceof ReceptionActionException) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        if ($e instanceof WalletChoiceRequiredException) {
            return response()->json([
                'message' => __('api.booking.wallet_choice_required'),
                'wallet_options' => $e->options,
            ], 422);
        }

        if ($e instanceof UnknownPaymentMethodException) {
            // The chosen gateway was switched off in config.
            return response()->json(['message' => __('api.payment.gateway_unavailable')], 503);
        }

        return $this->gatewayFailureResponse($e, $userId);
    }

    /**
     * The payment of a record made in this same request (a booking, a
     * registration). The record stands whatever happens to its payment, so
     * one that could not be opened is answered as `payment_error` beside
     * it — the member can pay again, or at the desk.
     *
     * @return array{payment: PaymentResource|null, redirect_url: string|null, payment_error?: string}
     */
    protected function payBesideNewRecord(PayableCheckout $checkout, PaymentPurpose $purpose, Model $record, User $member, string $method): array
    {
        try {
            $outcome = $checkout->pay($purpose, $record, $member, $method);
        } catch (ReceptionActionException $e) {
            return ['payment' => null, 'redirect_url' => null, 'payment_error' => __($e->messageKey, $e->params)];
        } catch (Throwable $e) {
            $failure = $this->gatewayFailure($e, $member->id) ?? throw $e;

            return ['payment' => null, 'redirect_url' => null, 'payment_error' => __($failure['key'])];
        }

        return [
            'payment' => $outcome->payment === null ? null : new PaymentResource($outcome->payment),
            'redirect_url' => $outcome->start->redirectUrl,
        ];
    }
}
```

- [ ] **Step 6: Route `PaymentController::store`'s payable branch through `PayableCheckout`**

In `app/Http/Controllers/Api/V1/Member/PaymentController.php`:
- replace `use RespondsToGatewayFailures;` (inside the class) with `use AnswersPayments;` and the import `App\Http\Controllers\Concerns\RespondsToGatewayFailures` with `App\Http\Controllers\Concerns\AnswersPayments`;
- add `PayableCheckout $checkout` to `store()`'s parameters;
- at the top of the `try` block, before `$code = ...`, insert:

```php
            // Something already made — a booking, a session, a registration —
            // is paid through the same service its own endpoint uses.
            if (in_array($purpose, [PaymentPurpose::Booking, PaymentPurpose::CoworkingSession, PaymentPurpose::EventRegistration], true)) {
                $payable = $payables->find($purpose, $request->validated('payable_id'), $member);
                $outcome = $checkout->pay($purpose, $payable, $member, $method, [
                    'wallet_owner_type' => $request->validated('wallet_owner_type'),
                    'wallet_owner_id' => $request->validated('wallet_owner_id'),
                ], $request->validated('coupon_code'));

                return $this->started($outcome->payment, $outcome->start, $outcome->coupon);
            }
```
(and `use App\Domain\Finance\Payments\PayableCheckout;`). This endpoint is deleted in Task 7; routing it through the service now proves the service against every existing pay-now test before any test moves.

- [ ] **Step 7: Run the new test and the existing pay-now tests**

Run: `PHP artisan test tests/Feature/Finance/PayableCheckoutTest.php tests/Feature/Finance/PayNowTest.php tests/Feature/Finance/PayNowWalletOwnerTest.php tests/Feature/Pricing/CouponApplicationTest.php tests/Feature/Experience/EventPaymentTest.php`
Expected: PASS.

- [ ] **Step 8: Style and commit**

```bash
PHP vendor/bin/pint --test app/Domain/Finance/Payments/CheckoutOutcome.php app/Domain/Finance/Payments/PayableCheckout.php app/Http/Controllers/Concerns/AnswersPayments.php app/Http/Controllers/Api/V1/Member/PaymentController.php tests/Feature/Finance/PayableCheckoutTest.php
git add app/Domain/Finance/Payments/CheckoutOutcome.php app/Domain/Finance/Payments/PayableCheckout.php app/Http/Controllers/Concerns/AnswersPayments.php app/Http/Controllers/Api/V1/Member/PaymentController.php tests/Feature/Finance/PayableCheckoutTest.php
git commit -m "refactor(payments): one checkout pays an existing record, called by purpose

PaymentController::store's coupon-then-start body becomes PayableCheckout,
and how a payment answers (fields, failures, a payment beside a record just
made) becomes the AnswersPayments trait — for the operation endpoints that
take over from POST member/payments. A code the record already carries is
ignored, so the app can re-send what it sent at creation.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Pay a booking by sending `POST member/bookings` again with `booking_id`

**Files:**
- Modify: `app/Http/Requests/Member/Booking/StoreBookingRequest.php`
- Modify: `app/Http/Controllers/Api/V1/Member/BookingController.php` (`store()` id branch; `startCardPayment()` replaced by `payBesideNewRecord()`; trait swap)
- Modify: `lang/en/api.php`, `lang/ar/api.php` (group `booking`: `booking_mismatch`)
- Test: `tests/Feature/Booking/BookingPayByIdTest.php`

**Interfaces:**
- Consumes: `PayableCheckout::pay()`, `AnswersPayments::{checkoutFields, paymentFailureResponse, payBesideNewRecord}` (Task 1).
- Produces: `POST member/bookings` accepts `booking_id` (nullable integer); with it, `space_id`/`start_at`/`end_at` are optional and must equal the booking's when sent. Lang key `api.booking.booking_mismatch`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Booking/BookingPayByIdTest.php`:

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A booking is made first, then paid. To pay it another way — after a card
 * failed, or later — the app sends the same create request again with the
 * booking's id: nothing is created, no creation rule runs, only how it is
 * paid changes (docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md).
 */
class BookingPayByIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-api-key',
            'paymera.terminal_id' => '99990001',
            'paymera.currency' => 'USD',
            'paymera.currency_exponent' => 2,
            'paymera.default_lang' => 'en',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(int $points = 0): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    private function openSpace(): Space
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 100, 'slot_granularity_minutes' => 60]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    private function confirmScreen(Space $space, array $extra = []): array
    {
        return array_merge([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
        ], $extra);
    }

    private function book(array $body)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', $body);
    }

    /**
     * The reproduced bug: card unreachable, back on the confirm screen,
     * confirm again with cash — refused before, because the member's own
     * unpaid booking held the slot.
     */
    public function test_after_a_card_that_could_not_open_the_same_screen_pays_the_same_booking_by_cash(): void
    {
        $this->member();
        $space = $this->openSpace();
        Http::fake(['*' => Http::response('', 500)]);

        $first = $this->book($this->confirmScreen($space, ['payment_method' => 'paymera']))
            ->assertCreated()
            ->assertJsonPath('payment', null);

        $this->book($this->confirmScreen($space, ['payment_method' => 'cash', 'booking_id' => $first->json('data.id')]))
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('next', 'pay_at_reception')
            ->assertJsonPath('payment', null);

        $this->assertSame(1, Booking::count());
    }

    public function test_sent_again_with_the_wallet_pays_it_now(): void
    {
        $member = $this->member(300);
        $space = $this->openSpace();
        Http::fake(['*' => Http::response('', 500)]);
        $id = $this->book($this->confirmScreen($space, ['payment_method' => 'paymera']))->json('data.id');

        $this->book($this->confirmScreen($space, ['payment_method' => 'wallet', 'booking_id' => $id]))
            ->assertOk()
            ->assertJsonPath('next', 'done')
            ->assertJsonPath('payment.status', 'accepted');

        $this->assertSame(PaymentState::Paid, Booking::findOrFail($id)->payment_state);
        $this->assertSame(200, app(WalletService::class)->generalBalance(
            Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->firstOrFail()
        ));
    }

    public function test_sent_again_with_the_card_opens_a_new_card_payment_for_the_same_booking(): void
    {
        $this->member();
        $space = $this->openSpace();
        $id = Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => auth()->id(),
            'start_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 11:00', 'Asia/Damascus')->utc(),
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => null,
            'price_points' => 100,
        ])->id;
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-9/en', 'paymentId' => 'pay-9'],
            ]),
        ]);

        $this->book(['booking_id' => $id, 'payment_method' => 'paymera'])
            ->assertOk()
            ->assertJsonPath('next', 'redirect')
            ->assertJsonPath('redirect_url', 'https://egate-t.paymera.test/start/pay-9/en');

        $this->assertSame($id, Payment::sole()->payable_id);
    }

    /**
     * Sent again with the id, no creation rule runs: a booking made at
     * 08:00 for 10:00, paid again at 09:30 — inside the 60-minute notice
     * a new booking needs — is not refused as too soon.
     */
    public function test_sent_again_close_to_the_start_is_not_refused_as_too_soon(): void
    {
        $this->member(300);
        $space = $this->openSpace();
        Http::fake(['*' => Http::response('', 500)]);
        $id = $this->book($this->confirmScreen($space, ['payment_method' => 'paymera']))->json('data.id');

        Carbon::setTestNow(Carbon::parse('2026-08-17 09:30:00', 'Asia/Damascus'));

        $this->book($this->confirmScreen($space, ['payment_method' => 'wallet', 'booking_id' => $id]))
            ->assertOk()
            ->assertJsonPath('next', 'done');
    }

    public function test_values_that_differ_from_the_booking_are_refused(): void
    {
        $this->member();
        $space = $this->openSpace();
        Http::fake(['*' => Http::response('', 500)]);
        $id = $this->book($this->confirmScreen($space, ['payment_method' => 'paymera']))->json('data.id');

        $this->book($this->confirmScreen($space, [
            'payment_method' => 'cash',
            'booking_id' => $id,
            'end_at' => '2026-08-17T12:00:00+03:00',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.booking.booking_mismatch', [], 'en'));
    }

    public function test_another_members_booking_is_not_found(): void
    {
        $this->member();
        $someoneElse = Booking::factory()->create(['payment_state' => PaymentState::Unpaid, 'price_points' => 100]);

        $this->book(['booking_id' => $someoneElse->id, 'payment_method' => 'cash'])->assertNotFound();
    }

    public function test_a_paid_booking_is_not_paid_again(): void
    {
        $this->member(300);
        $space = $this->openSpace();
        $id = $this->book($this->confirmScreen($space, ['payment_method' => 'wallet']))->json('data.id');

        $this->book(['booking_id' => $id, 'payment_method' => 'wallet'])
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.payment.already_paid', [], 'en'));
    }

    public function test_a_cancelled_booking_cannot_be_paid(): void
    {
        $this->member(300);
        $cancelled = Booking::factory()->create([
            'user_id' => auth()->id(),
            'status' => BookingStatus::Cancelled,
            'payment_state' => PaymentState::Unpaid,
            'price_points' => 100,
        ]);

        $this->book(['booking_id' => $cancelled->id, 'payment_method' => 'wallet'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.payment.booking_not_payable', [], 'en'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PHP artisan test tests/Feature/Booking/BookingPayByIdTest.php`
Expected: FAIL — the first case gets `422 "This space is not available for the requested time."` on the second request (the reproduced bug); the id-only cases fail validation on `space_id`.

- [ ] **Step 3: Accept `booking_id` in `StoreBookingRequest`**

Replace the first three rules and add `booking_id`:

```php
            // With the id of a booking already made, nothing is created: only
            // how it is paid changes — so the creation fields are optional,
            // and any sent must be that booking's
            // (docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md).
            'booking_id' => ['nullable', 'integer'],
            'space_id' => ['required_without:booking_id', 'integer', 'exists:spaces,id'],
            'start_at' => ['required_without:booking_id', 'date'],
            'end_at' => ['required_without:booking_id', 'date', 'after:start_at'],
```

- [ ] **Step 4: The id branch in `BookingController::store`**

In `app/Http/Controllers/Api/V1/Member/BookingController.php`:
- trait: replace `use RespondsToGatewayFailures;` with `use AnswersPayments;` (import `App\Http\Controllers\Concerns\AnswersPayments`, drop the `RespondsToGatewayFailures` import);
- `store()` signature gains `PayableCheckout $checkout` (import `App\Domain\Finance\Payments\PayableCheckout`);
- first line of `store()`:

```php
        if ($request->validated('booking_id') !== null) {
            return $this->payExisting($request, $checkout);
        }
```
- the card start after creation becomes:

```php
        $card = $method !== null && in_array($method, $methods->gatewayKeys(), true)
            ? $this->payBesideNewRecord($checkout, PaymentPurpose::Booking, $booking, $request->user(), $method)
            : [];
```
- delete the private `startCardPayment()` method (its body now lives in `AnswersPayments::payBesideNewRecord()`), and remove the `PaymentService $payments` parameter from `store()` if nothing else in `store()` uses it;
- add:

```php
    /**
     * The confirm screen sent again with the booking it made: nothing is
     * created and no creation rule runs — the booking exists and holds its
     * slot, and its price was fixed when it was made. Only how it is paid
     * changes, through the same checkout every operation uses.
     */
    private function payExisting(StoreBookingRequest $request, PayableCheckout $checkout): JsonResponse
    {
        $member = $request->user();
        $booking = Booking::query()->where('user_id', $member->id)->findOrFail($request->validated('booking_id'));

        if (! $this->sentValuesMatch($booking, $request)) {
            return response()->json(['message' => __('api.booking.booking_mismatch')], 422);
        }

        try {
            $outcome = $checkout->pay(PaymentPurpose::Booking, $booking, $member, $request->validated('payment_method'), [
                'wallet_owner_type' => $request->validated('wallet_owner_type'),
                'wallet_owner_id' => $request->validated('wallet_owner_id'),
            ], $request->validated('coupon_code'));
        } catch (Throwable $e) {
            return $this->paymentFailureResponse($e, $member->id) ?? throw $e;
        }

        return response()->json(
            ['data' => new BookingResource($booking->refresh())]
                + $this->checkoutFields($outcome)
                + ($outcome->coupon !== null ? ['coupon' => $outcome->coupon] : [])
        );
    }

    /**
     * The confirm screen may send its values unchanged; any it sends must
     * be this booking's — a stale id in the app is refused, never paid.
     */
    private function sentValuesMatch(Booking $booking, StoreBookingRequest $request): bool
    {
        $space = $request->validated('space_id');
        $start = $request->validated('start_at');
        $end = $request->validated('end_at');

        return ($space === null || (int) $space === $booking->space_id)
            && ($start === null || Carbon::parse($start, 'UTC')->equalTo($booking->start_at))
            && ($end === null || Carbon::parse($end, 'UTC')->equalTo($booking->end_at));
    }
```

- [ ] **Step 5: Add the mismatch message**

`lang/en/api.php`, group `booking`:
```php
        'booking_mismatch' => 'These details do not match the booking being paid. Start the booking again.',
```
`lang/ar/api.php`, group `booking`:
```php
        'booking_mismatch' => 'هذه التفاصيل لا تطابق الحجز المراد دفعه. ابدأ الحجز من جديد.',
```

- [ ] **Step 6: Run the new test and the booking-payment tests**

Run: `PHP artisan test tests/Feature/Booking/BookingPayByIdTest.php tests/Feature/Booking/BookingPaymentMethodTest.php tests/Feature/Finance/CardStartFailureTest.php tests/Feature/Booking/BookingControllerTest.php`
Expected: PASS.

- [ ] **Step 7: Style and commit**

```bash
PHP vendor/bin/pint --test app/Http/Requests/Member/Booking/StoreBookingRequest.php app/Http/Controllers/Api/V1/Member/BookingController.php tests/Feature/Booking/BookingPayByIdTest.php lang/en/api.php lang/ar/api.php
git add app/Http/Requests/Member/Booking/StoreBookingRequest.php app/Http/Controllers/Api/V1/Member/BookingController.php tests/Feature/Booking/BookingPayByIdTest.php lang/en/api.php lang/ar/api.php
git commit -m "feat(bookings): pay a booking by sending its request again with its id

With booking_id, POST member/bookings creates nothing and runs no creation
rule: it pays that booking by the method sent, through PayableCheckout.
Fixes the confirm screen's retry, which was refused because the member's
own unpaid booking held the slot.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Pay a registration by sending `register` again with `ticket_id`

**Files:**
- Modify: `app/Http/Requests/Experience/RegisterForEventRequest.php`
- Modify: `app/Http/Controllers/Api/V1/Member/EventController.php` (`register()` id branch; `startCardPayment()` → `payBesideNewRecord()`; trait swap)
- Test: `tests/Feature/Experience/EventRegistrationPayByIdTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces: `POST member/events/{id}/register` accepts `ticket_id`; with it the form rules are skipped and the registration (the member's own, for that event, seat-holding) is paid or — while pending approval — has its stored choice updated.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Experience/EventRegistrationPayByIdTest.php`. Copy the `setUp()`, `member()` and paid-event factory helpers from `tests/Feature/Experience/EventPaymentTest.php` (same namespace, same seeders, same Paymera config) into this class, then add:

```php
    private function register(int $eventId, array $body)
    {
        return $this->withHeader('lang', 'en')->postJson("/api/v1/member/events/{$eventId}/register", $body);
    }

    public function test_an_approved_unpaid_registration_is_paid_by_sending_the_request_again_with_its_ticket(): void
    {
        $member = $this->member(500);
        $event = $this->paidEvent(80); // approved on the spot, 80 points
        $ticket = $this->register($event->id, ['payment_method' => 'cash'])->json('data.ticket_id');

        $this->register($event->id, ['ticket_id' => $ticket, 'payment_method' => 'wallet'])
            ->assertOk()
            ->assertJsonPath('data.ticket_id', $ticket)
            ->assertJsonPath('data.next', 'done');

        $this->assertSame(EventPaymentState::Paid, EventAttendee::findOrFail($ticket)->payment_state);
        $this->assertSame(1, EventAttendee::count());
    }

    public function test_the_form_is_not_asked_for_again(): void
    {
        $this->member(500);
        $event = $this->paidEventWithRequiredForm(80);
        $ticket = $this->register($event->id, ['payment_method' => 'cash'] + $this->validFormAnswers($event))->json('data.ticket_id');

        $this->register($event->id, ['ticket_id' => $ticket, 'payment_method' => 'wallet'])->assertOk();
    }

    public function test_a_pending_registration_has_its_stored_choice_updated_and_nothing_charged(): void
    {
        $member = $this->member(500);
        $event = $this->paidEvent(80, requiresApproval: true);
        $ticket = $this->register($event->id, ['payment_method' => 'cash'])->json('data.ticket_id');

        $this->register($event->id, ['ticket_id' => $ticket, 'payment_method' => 'wallet'])
            ->assertOk()
            ->assertJsonPath('data.next', 'pay_at_reception');

        $attendee = EventAttendee::findOrFail($ticket);
        $this->assertSame('wallet', $attendee->payment_method);
        $this->assertSame(EventPaymentState::Unpaid, $attendee->payment_state);
    }

    public function test_another_members_ticket_is_not_found(): void
    {
        $this->member(500);
        $event = $this->paidEvent(80);
        $someoneElse = EventAttendee::factory()->create(['event_id' => $event->id]);

        $this->register($event->id, ['ticket_id' => $someoneElse->id, 'payment_method' => 'wallet'])->assertNotFound();
    }

    public function test_without_a_ticket_registering_again_is_still_refused(): void
    {
        $this->member(500);
        $event = $this->paidEvent(80);
        $this->register($event->id, ['payment_method' => 'cash'])->assertCreated();

        $this->register($event->id, ['payment_method' => 'wallet'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('registration');
    }
```

`paidEvent(int $points, bool $requiresApproval = false)`, `paidEventWithRequiredForm(int $points)` and `validFormAnswers(Event $event)`: build them from what `EventPaymentTest` and `tests/Feature/Experience/EventRegistrationTest.php` already use to make a published, open, paid event (`is_free => false`, `price_points => $points`, `requires_approval => $requiresApproval`) and one with a required form field — reuse those tests' factories and states verbatim, do not invent new ones.

- [ ] **Step 2: Run test to verify it fails**

Run: `PHP artisan test tests/Feature/Experience/EventRegistrationPayByIdTest.php`
Expected: FAIL — the id requests answer `422 already_registered` (and the form case fails form validation).

- [ ] **Step 3: Skip the form with a ticket**

`RegisterForEventRequest::rules()`:

```php
        // With the ticket of a registration already made, nothing is
        // registered: only how it is paid changes — so the form is not asked
        // for again.
        $form = $this->filled('ticket_id') ? [] : $this->formFieldRules();

        return array_merge($form, [
            'ticket_id' => ['nullable', 'integer'],
            // ... the existing payment_method, wallet_owner_type,
            // wallet_owner_id and coupon_code rules, unchanged
        ]);
```

- [ ] **Step 4: The ticket branch in `EventController::register`**

In `app/Http/Controllers/Api/V1/Member/EventController.php`:
- `use RespondsToGatewayFailures;` → `use AnswersPayments;` (imports likewise);
- `register()` gains `PayableCheckout $checkout`;
- first lines of `register()`, after `$event = Event::findOrFail($id);`:

```php
        if ($request->validated('ticket_id') !== null) {
            return $this->payExisting($request, $event, $checkout);
        }
```
- the card start after registering becomes `$result += $this->payBesideNewRecord($checkout, PaymentPurpose::EventRegistration, $attendee, $request->user(), $method);` and the private `startCardPayment()` is deleted;
- add:

```php
    /**
     * The registration sent again with its ticket: nothing is registered
     * and no registration rule runs (window, capacity, form). An approved
     * seat is paid by the method sent; one still pending approval only has
     * its stored choice updated — nothing is charged until approval.
     */
    private function payExisting(RegisterForEventRequest $request, Event $event, PayableCheckout $checkout): JsonResponse
    {
        $member = $request->user();
        $attendee = EventAttendee::query()
            ->where('user_id', $member->id)
            ->where('event_id', $event->id)
            ->seatHolding()
            ->findOrFail($request->validated('ticket_id'));

        $method = $request->validated('payment_method') ?? WalletMethod::KEY;

        if ($attendee->status === EventRegistrationStatus::Pending) {
            $attendee->forceFill([
                'payment_method' => $method,
                'payment_wallet_owner_type' => $request->validated('wallet_owner_type'),
                'payment_wallet_owner_id' => $request->validated('wallet_owner_id'),
            ])->save();

            return response()->json(['data' => [
                'ticket_id' => $attendee->id,
                'status' => $attendee->status->value,
                'payment' => null,
                'redirect_url' => null,
                'next' => PaymentStart::PAY_AT_RECEPTION,
            ]]);
        }

        try {
            $outcome = $checkout->pay(PaymentPurpose::EventRegistration, $attendee, $member, $method, [
                'wallet_owner_type' => $request->validated('wallet_owner_type'),
                'wallet_owner_id' => $request->validated('wallet_owner_id'),
            ], $request->validated('coupon_code'));
        } catch (Throwable $e) {
            return $this->paymentFailureResponse($e, $member->id) ?? throw $e;
        }

        return response()->json(['data' => [
            'ticket_id' => $attendee->id,
            'status' => $attendee->refresh()->status->value,
        ] + $this->checkoutFields($outcome) + ($outcome->coupon !== null ? ['coupon' => $outcome->coupon] : [])]);
    }
```
(imports: `App\Domain\Finance\Payments\PayableCheckout`, `App\Domain\Finance\Payments\PaymentStart`, `App\Domain\Finance\Payments\Methods\WalletMethod`.)

- [ ] **Step 5: Run the new test and the event tests**

Run: `PHP artisan test tests/Feature/Experience/EventRegistrationPayByIdTest.php tests/Feature/Experience`
Expected: PASS (apart from any of the 17 pre-existing failures that live in `tests/Feature/Experience`).

- [ ] **Step 6: Style and commit**

```bash
PHP vendor/bin/pint --test app/Http/Requests/Experience/RegisterForEventRequest.php app/Http/Controllers/Api/V1/Member/EventController.php tests/Feature/Experience/EventRegistrationPayByIdTest.php
git add app/Http/Requests/Experience/RegisterForEventRequest.php app/Http/Controllers/Api/V1/Member/EventController.php tests/Feature/Experience/EventRegistrationPayByIdTest.php
git commit -m "feat(events): pay a registration by sending register again with its ticket

With ticket_id, POST member/events/{id}/register registers nothing and asks
for no form: an approved seat is paid by the method sent through
PayableCheckout; one pending approval has its stored choice updated.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `POST member/coworking-sessions/{type}/{id}/pay`

**Files:**
- Create: `app/Http/Requests/Member/PayCoworkingSessionRequest.php`
- Modify: `app/Http/Controllers/Api/V1/Member/CoworkingSessionController.php` (`pay()`; `use AnswersPayments;`)
- Modify: `routes/api/v1/member.php`
- Modify: `app/Domain/Booking/Services/SessionEndService.php` (docblock of `settleAsChosen()`: "a card is paid by the member in the app through POST member/coworking-sessions/{type}/{id}/pay")
- Test: `tests/Feature/Booking/CoworkingSessionPayTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces: route `POST member/coworking-sessions/{type}/{id}/pay` (`type` ∈ `booking|walkin`, numeric `id`), body `{payment_method (required), wallet_owner_type?, wallet_owner_id?, coupon_code?}`, answer 201 `{data: {payment, redirect_url, next}, coupon?}`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Booking/CoworkingSessionPayTest.php` — same `setUp()`, `member()`, `walletOf()` and `fakeGateway()` as `tests/Feature/Finance/PayNowTest.php` (copy them), then:

```php
    private function pay(string $type, int $id, array $body)
    {
        return $this->withHeader('lang', 'en')->postJson("/api/v1/member/coworking-sessions/{$type}/{$id}/pay", $body);
    }

    public function test_an_open_session_cannot_be_paid_but_a_closed_one_can(): void
    {
        $member = $this->member(300);
        Sanctum::actingAs($member, ['*']);

        $open = WalkinSession::factory()->create(['user_id' => $member->id, 'checked_out_at' => null]);
        $this->pay('walkin', $open->id, ['payment_method' => 'wallet'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.reception.not_yet_checked_out', [], 'en'));

        $closed = WalkinSession::factory()->create([
            'user_id' => $member->id,
            'checked_out_at' => now()->subHour(),
            'price_points' => 150,
            'amount_owed' => '15.00',
            'payment_state' => PaymentState::Unpaid,
        ]);
        $this->pay('walkin', $closed->id, ['payment_method' => 'wallet'])
            ->assertCreated()
            ->assertJsonPath('data.next', 'done');

        $this->assertSame(PaymentState::Paid, $closed->refresh()->payment_state);
        $this->assertSame(150, app(WalletService::class)->generalBalance($this->walletOf($member)));
    }

    public function test_a_closed_session_is_paid_by_card(): void
    {
        $member = $this->member();
        Sanctum::actingAs($member, ['*']);
        $this->fakeGateway(amountMinor: 1500);
        $closed = WalkinSession::factory()->create([
            'user_id' => $member->id,
            'checked_out_at' => now()->subHour(),
            'price_points' => 150,
            'amount_owed' => '15.00',
            'payment_state' => PaymentState::Unpaid,
        ]);

        $this->pay('walkin', $closed->id, ['payment_method' => 'paymera'])
            ->assertCreated()
            ->assertJsonPath('data.next', 'redirect')
            ->assertJsonPath('data.redirect_url', 'https://egate-t.paymera.test/start/pay-9/en');
    }

    public function test_someone_elses_session_is_not_found(): void
    {
        Sanctum::actingAs($this->member(300), ['*']);
        $theirs = WalkinSession::factory()->create(['checked_out_at' => now()->subHour(), 'payment_state' => PaymentState::Unpaid]);

        $this->pay('walkin', $theirs->id, ['payment_method' => 'wallet'])->assertNotFound();
    }

    public function test_a_method_is_required(): void
    {
        $member = $this->member(300);
        Sanctum::actingAs($member, ['*']);
        $closed = WalkinSession::factory()->create(['user_id' => $member->id, 'checked_out_at' => now()->subHour()]);

        $this->pay('walkin', $closed->id, [])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PHP artisan test tests/Feature/Booking/CoworkingSessionPayTest.php`
Expected: FAIL — 404/405 (no route).

- [ ] **Step 3: The request**

`app/Http/Requests/Member/PayCoworkingSessionRequest.php`:

```php
<?php

namespace App\Http\Requests\Member;

use App\Domain\Finance\Payments\PaymentMethodRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paying a closed coworking session from the app — the card the member
 * chose when ending it, or another method.
 */
class PayCoworkingSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', 'string', Rule::in(array_keys(app(PaymentMethodRegistry::class)->enabled()))],
            'wallet_owner_type' => ['nullable', 'string', Rule::in(['user', 'company']), 'required_with:wallet_owner_id'],
            'wallet_owner_id' => ['nullable', 'integer', 'required_with:wallet_owner_type'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ];
    }
}
```

- [ ] **Step 4: The action**

`CoworkingSessionController` gains `use AnswersPayments;` and:

```php
    /**
     * A closed session is paid as the member asks — the card chosen when
     * ending it, the wallet, or at the desk. There is no create request to
     * send again for a session, so it has its own endpoint. Not the
     * member's: 404, as for the report.
     */
    public function pay(PayCoworkingSessionRequest $request, string $type, int $id, CoworkingSessionService $sessions, PayableCheckout $checkout): JsonResponse
    {
        $session = $sessions->find($type, $id);

        abort_if($session === null || $session->user_id !== $request->user()->id, 404);

        try {
            $outcome = $checkout->pay(
                $session instanceof Booking ? PaymentPurpose::Booking : PaymentPurpose::CoworkingSession,
                $session,
                $request->user(),
                $request->validated('payment_method'),
                [
                    'wallet_owner_type' => $request->validated('wallet_owner_type'),
                    'wallet_owner_id' => $request->validated('wallet_owner_id'),
                ],
                $request->validated('coupon_code'),
            );
        } catch (Throwable $e) {
            return $this->paymentFailureResponse($e, $request->user()->id) ?? throw $e;
        }

        return response()->json(
            ['data' => $this->checkoutFields($outcome)] + ($outcome->coupon !== null ? ['coupon' => $outcome->coupon] : []),
            201,
        );
    }
```
(imports: `App\Domain\Booking\Models\Booking`, `App\Domain\Finance\Enums\PaymentPurpose`, `App\Domain\Finance\Payments\PayableCheckout`, `App\Http\Controllers\Concerns\AnswersPayments`, `App\Http\Requests\Member\PayCoworkingSessionRequest`, `Throwable`.)

- [ ] **Step 5: The route**

In `routes/api/v1/member.php`, right after the `coworking-sessions/{type}/{id}/report` route:

```php
// A closed session paid from the app (the card chosen when ending it, or
// another method) — docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md.
Route::post('coworking-sessions/{type}/{id}/pay', [CoworkingSessionController::class, 'pay'])
    ->whereIn('type', ['booking', 'walkin'])
    ->whereNumber('id');
```

- [ ] **Step 6: Run the test**

Run: `PHP artisan test tests/Feature/Booking/CoworkingSessionPayTest.php tests/Feature/Booking/CoworkingSessionEndTest.php`
Expected: PASS.

- [ ] **Step 7: Style and commit**

```bash
PHP vendor/bin/pint --test app/Http/Requests/Member/PayCoworkingSessionRequest.php app/Http/Controllers/Api/V1/Member/CoworkingSessionController.php app/Domain/Booking/Services/SessionEndService.php routes/api/v1/member.php tests/Feature/Booking/CoworkingSessionPayTest.php
git add app/Http/Requests/Member/PayCoworkingSessionRequest.php app/Http/Controllers/Api/V1/Member/CoworkingSessionController.php app/Domain/Booking/Services/SessionEndService.php routes/api/v1/member.php tests/Feature/Booking/CoworkingSessionPayTest.php
git commit -m "feat(coworking): pay a closed session from the app through its own endpoint

POST member/coworking-sessions/{type}/{id}/pay takes over the card a member
chose when ending a session, which POST member/payments used to open.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `POST member/wallet/top-ups`, prepaid methods only

**Files:**
- Create: `app/Http/Requests/Member/StoreTopUpRequest.php`
- Create: `app/Http/Controllers/Api/V1/Member/TopUpController.php`
- Modify: `routes/api/v1/member.php` (route + the wallet comment)
- Modify: `app/Domain/Finance/Payments/Methods/CashMethod.php` (`supports()` excludes `WalletTopUp`)
- Modify: `app/Domain/Finance/Payments/PaymentMethodOptions.php` (drop the cash-top-up hint branch)
- Modify: `lang/en/api.php`, `lang/ar/api.php` (remove `payment.hints.cash_top_up`)
- Modify: `app/Http/Controllers/Api/V1/Member/WalletController.php` (docblock: topping up is `POST member/wallet/top-ups`)
- Modify tests that pin the old cash top-up: `tests/Feature/Finance/MemberPaymentMethodsTest.php` (`test_a_top_up_lists_cash_with_directions_and_the_card_and_no_amount`), `tests/Feature/Finance/PaymentMethodRegistryTest.php` (`availableFor(WalletTopUp)` expectation)
- Test: `tests/Feature/Finance/MemberTopUpTest.php`

**Interfaces:**
- Consumes: `StartPayment::handle(User $user, PaymentPurpose $purpose, ?Plan $plan = null, ?int $amountMinor = null, ?string $lang = null, ?Coupon $coupon = null): array{payment: Payment, url: string}`; `PaymentAmountCalculator::minimumTopUp(): array{points:int, amount:string, amount_minor:int, currency:string}`.
- Produces: route `POST member/wallet/top-ups` `{amount (required), payment_method?}` → 201 `{data: {payment, redirect_url, next: "redirect"}}`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Finance/MemberTopUpTest.php` — copy `setUp()` from `tests/Feature/Finance/MinimumTopUpTest.php` (SYP gateway config, exchange rate, `Http::fake` create-payment, member acting with `['member-app']`), then:

```php
    private function topUp(array $body)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/wallet/top-ups', $body);
    }

    public function test_a_top_up_opens_a_card_payment(): void
    {
        $this->topUp(['amount' => '10000'])
            ->assertCreated()
            ->assertJsonPath('data.next', 'redirect')
            ->assertJsonPath('data.redirect_url', 'https://egate-t.paymera.test/start/pay-1/ar')
            ->assertJsonPath('data.payment.status', 'pending');
    }

    public function test_cash_is_reception_only(): void
    {
        $this->topUp(['amount' => '10000', 'payment_method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Payment::count());
    }

    public function test_points_cannot_buy_points(): void
    {
        $this->topUp(['amount' => '10000', 'payment_method' => 'wallet'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');
    }

    public function test_the_minimum_is_named_in_money(): void
    {
        $this->topUp(['amount' => '9900'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'The minimum top-up is 10,000 SYP (10 points).');
    }

    public function test_an_amount_is_required(): void
    {
        $this->topUp([])->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_the_payment_methods_for_a_top_up_are_prepaid_only(): void
    {
        $keys = array_column($this->getJson('/api/v1/member/payment-methods?purpose=wallet_top_up')->json('data.methods'), 'key');

        $this->assertSame(['paymera'], $keys);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PHP artisan test tests/Feature/Finance/MemberTopUpTest.php`
Expected: FAIL — 404/405 on the route; the methods case lists `['cash', 'paymera']`.

- [ ] **Step 3: Cash does not top up from the app**

`CashMethod::supports()`:

```php
    /**
     * Not an extension: it is settled from the wallet as it happens, or the
     * booking is left owing it. Not a member's top-up: cash for points is
     * taken by reception (admin/reception/wallet-top-ups), which writes its
     * own row and never asks this.
     */
    public function supports(PaymentPurpose $purpose): bool
    {
        return ! in_array($purpose, [PaymentPurpose::BookingExtension, PaymentPurpose::WalletTopUp], true);
    }
```

In `PaymentMethodOptions::for()` delete the block:
```php
            // Cash for a top-up starts nothing: it tells the member where to go.
            if ($key === CashMethod::KEY && $purpose === PaymentPurpose::WalletTopUp) {
                $option['hint'] = __('api.payment.hints.cash_top_up');
            }
```
(keep `'hint' => null` in `$option`; drop the `CashMethod` import if now unused). In both lang files remove the `'cash_top_up'` entry and its `'hints'` group if it becomes empty, with its comment.

- [ ] **Step 4: The request**

`app/Http/Requests/Member/StoreTopUpRequest.php`:

```php
<?php

namespace App\Http\Requests\Member;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Services\PaymentAmountCalculator;
use App\Rules\AmountFitsCurrency;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A member topping up their own wallet from the app — by a prepaid method
 * only; cash for points is reception's. `amount` is what they want to add,
 * in the gateway currency's major units.
 */
class StoreTopUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Omitted: the card. Only methods that can top up (cash cannot,
            // nor can points buy points).
            'payment_method' => ['nullable', 'string', Rule::in(array_keys(
                app(PaymentMethodRegistry::class)->availableFor(PaymentPurpose::WalletTopUp)
            ))],
            // A plain decimal: `numeric` alone lets `1e3` through, which
            // bcmath refuses (amountMinor()).
            'amount' => [
                'required',
                'numeric',
                'regex:/^\d+(\.\d{1,6})?$/',
                'min:0.01',
                new AmountFitsCurrency((string) config('paymera.currency')),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // A top-up has a floor, named in money as well as points, compared
            // in money. PaymentPricingException (no exchange rate) is not
            // caught: it is ours, rendered as a 503 (bootstrap/app.php).
            $minimum = app(PaymentAmountCalculator::class)->minimumTopUp();

            if ((int) $this->amountMinor() < $minimum['amount_minor']) {
                $validator->errors()->add('amount', __('api.wallet.top_up_below_minimum', [
                    'amount' => number_format((int) $minimum['amount']),
                    'currency' => $minimum['currency'],
                    'points' => $minimum['points'],
                ]));
            }
        });
    }

    /**
     * The amount in the gateway's integer minor units, with bcmath — never
     * (int) round($x * 100), which turns 10.10 into 1009.
     */
    public function amountMinor(): ?int
    {
        $amount = $this->input('amount');

        if ($amount === null) {
            return null;
        }

        return (int) bcmul((string) $amount, bcpow('10', (string) config('paymera.currency_exponent'), 0), 0);
    }
}
```

- [ ] **Step 5: The controller**

`app/Http/Controllers/Api/V1/Member/TopUpController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Exceptions\UnknownPaymentMethodException;
use App\Domain\Finance\Services\StartPayment;
use App\Http\Controllers\Concerns\AnswersPayments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StoreTopUpRequest;
use App\Http\Resources\Finance\PaymentResource;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * POST member/wallet/top-ups — the member adds points to their own wallet by
 * card; the app opens `redirect_url` in a WebView and polls
 * GET member/payments/{id}. Answered exactly as POST member/payments
 * answered a top-up, which this replaces.
 */
class TopUpController extends Controller
{
    use AnswersPayments;

    public function store(StoreTopUpRequest $request, StartPayment $card): JsonResponse
    {
        $member = $request->user();

        try {
            ['payment' => $payment, 'url' => $url] = $card->handle(
                user: $member,
                purpose: PaymentPurpose::WalletTopUp,
                amountMinor: $request->amountMinor(),
                lang: app()->getLocale(),
            );
        } catch (UnknownPaymentMethodException) {
            return response()->json(['message' => __('api.payment.gateway_unavailable')], 503);
        } catch (Throwable $e) {
            return $this->gatewayFailureResponse($e, $member->id) ?? throw $e;
        }

        return response()->json([
            'data' => [
                'payment' => new PaymentResource($payment),
                'redirect_url' => $url,
                'next' => 'redirect',
            ],
        ], 201);
    }
}
```

`StartPayment` opens Paymera only (`PaymeraGateway::KEY`); `payment_method` is validated to the methods that can top up, which today is exactly `paymera`. A second gateway will need `StartPayment` to take the key — out of scope here.

- [ ] **Step 6: The route**

In `routes/api/v1/member.php`, after the `wallet/transactions/{transaction}` route, and rewrite the comment above the wallet routes to say topping up is `POST wallet/top-ups`:

```php
Route::post('wallet/top-ups', [TopUpController::class, 'store']);
```
(import `App\Http\Controllers\Api\V1\Member\TopUpController`).

- [ ] **Step 7: Update the two tests that pin cash top-ups**

`MemberPaymentMethodsTest::test_a_top_up_lists_cash_with_directions_and_the_card_and_no_amount` → rename to `test_a_top_up_lists_the_card_only_and_no_amount` and replace its body's method assertions with:

```php
            ->assertJsonPath('data.methods.0.key', 'paymera')
            ->assertJsonPath('data.methods.0.hint', null);

        // Points cannot buy points; cash for points is reception's.
        $this->assertSame(['paymera'], array_column($response->json('data.methods'), 'key'));
```
`PaymentMethodRegistryTest`: the `availableFor(PaymentPurpose::WalletTopUp)` expectation becomes `['paymera', 'fake']` and its comment says cash is reception's.

- [ ] **Step 8: Run**

Run: `PHP artisan test tests/Feature/Finance/MemberTopUpTest.php tests/Feature/Finance/MemberPaymentMethodsTest.php tests/Feature/Finance/PaymentMethodRegistryTest.php tests/Feature/Booking/WalletTopUpControllerTest.php tests/Feature/Finance/CashMethodTest.php`
Expected: PASS — `WalletTopUpControllerTest` (reception) unchanged and green.

- [ ] **Step 9: Style and commit**

```bash
PHP vendor/bin/pint --test app/Http/Requests/Member/StoreTopUpRequest.php app/Http/Controllers/Api/V1/Member/TopUpController.php app/Domain/Finance/Payments/Methods/CashMethod.php app/Domain/Finance/Payments/PaymentMethodOptions.php app/Http/Controllers/Api/V1/Member/WalletController.php routes/api/v1/member.php tests/Feature/Finance/MemberTopUpTest.php tests/Feature/Finance/MemberPaymentMethodsTest.php tests/Feature/Finance/PaymentMethodRegistryTest.php
git add -A app/Http/Requests/Member/StoreTopUpRequest.php app/Http/Controllers/Api/V1/Member/TopUpController.php app/Domain/Finance/Payments/Methods/CashMethod.php app/Domain/Finance/Payments/PaymentMethodOptions.php app/Http/Controllers/Api/V1/Member/WalletController.php routes/api/v1/member.php lang/en/api.php lang/ar/api.php tests/Feature/Finance/MemberTopUpTest.php tests/Feature/Finance/MemberPaymentMethodsTest.php tests/Feature/Finance/PaymentMethodRegistryTest.php
git commit -m "feat(wallet): members top up through POST member/wallet/top-ups, by card only

Cash for points is reception's: CashMethod no longer supports a member
top-up, so payment-methods offers the card alone and its go-to-reception
hint goes. Reception top-ups write their own row and are unaffected.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Move every test off `POST member/payments`

Each call moves to the operation's endpoint; **the assertions stay**. Apply this mapping to every call site listed below (line numbers are as of `0e6db0c`; find each by its `postJson('/api/v1/member/payments'` text):

| old body | new request | response changes |
|---|---|---|
| `purpose: wallet_top_up` (+ `method: paymera`, `amount`) | `POST /api/v1/member/wallet/top-ups` with `amount` (and `payment_method` if `method` was sent) | none |
| `purpose: wallet_top_up, method: cash` | the case is covered by `MemberTopUpTest::test_cash_is_reception_only`; delete it | — |
| `purpose: plan_purchase` (+ `plan_id`, `coupon_code`, `amount`) | `POST /api/v1/member/memberships` with `plan_id`, `payment_method: 'paymera'`, `coupon_code` if sent; drop `amount` | none (`MembershipController::startCard` answers the same shape) |
| `purpose: booking, payable_id: X, method: M` | `POST /api/v1/member/bookings` with `booking_id: X, payment_method: M` (+ `wallet_owner_*`, `coupon_code` if sent) | `assertCreated()` → `assertOk()`; `data.payment` → `payment`; `data.redirect_url` → `redirect_url`; `data.next` → `next` |
| `purpose: event_registration, payable_id: X, method: M` | `POST /api/v1/member/events/{EVENT_ID}/register` with `ticket_id: X, payment_method: M` | `assertCreated()` → `assertOk()`; paths under `data.` unchanged |
| `purpose: coworking_session, payable_id: X, method: M` | `POST /api/v1/member/coworking-sessions/walkin/X/pay` with `payment_method: M` | none |
| `purpose: <anything>` asserting validation on `purpose` | delete — there is no `purpose` field any more | — |

**Files and call sites:**
- `tests/Feature/Booking/BookingExtensionOwedTest.php:192` (booking)
- `tests/Feature/Experience/EventPaymentTest.php:283` (event_registration)
- `tests/Feature/Finance/CardStartFailureTest.php:139, 178` (booking)
- `tests/Feature/Finance/GatewayLanguageTest.php:58` (top-up)
- `tests/Feature/Finance/MemberWalletTest.php:342, 349` (cash top-up → delete `test_a_cash_top_up_from_the_app_only_sends_the_member_to_reception`), `:361, 366` (top-up; `method: paymera` → `payment_method`), `:378` (top-up)
- `tests/Feature/Finance/MinimumTopUpTest.php:60` (top-up helper)
- `tests/Feature/Finance/MoneyAmountSpellingTest.php:133, 140` (top-up)
- `tests/Feature/Finance/PaymentNotificationsTest.php:170` (booking)
- `tests/Feature/Finance/PaymentsTableTest.php:273` (top-up); `:299` is `test_paying_for_a_record_requires_naming_it`, which only checks that `payable_id` is required by `POST member/payments` — that field no longer exists (an id is part of each operation's own request, pinned by Tasks 2–4): delete the test
- `tests/Feature/Finance/PaymentStateBlockTest.php:84` (booking)
- `tests/Feature/Finance/PaymeraChargeCurrencyTest.php:189, 209` (top-up), `:234, 269` (plan)
- `tests/Feature/Finance/PaymeraCreatePaymentPayloadTest.php:70` (top-up), `:98` (booking)
- `tests/Feature/Finance/PaymeraPaymentTest.php:118, 191, 216, 233, 549` (top-up), `:152, 524, 538` (plan)
- `tests/Feature/Finance/PaymeraSecretsAreNeverLoggedTest.php:217` (top-up)
- `tests/Feature/Finance/PayNowTest.php:130` — the `payNow()` helper: replace it with three helpers and route each test through the right one:

```php
    private function payBooking(Booking $booking, array $body)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', ['booking_id' => $booking->id] + $body);
    }

    private function paySession(WalkinSession $session, array $body)
    {
        return $this->withHeader('lang', 'en')->postJson("/api/v1/member/coworking-sessions/walkin/{$session->id}/pay", $body);
    }

    private function topUp(array $body)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/wallet/top-ups', $body);
    }
```
  (`'method' =>` → `'payment_method' =>`; booking cases take the booking response changes above; `test_the_old_top_up_request_answers_as_before_with_next` becomes `test_a_top_up_answers_as_before_with_next` through `topUp()`; `test_an_open_session_cannot_be_paid_but_a_closed_one_can` is already in `CoworkingSessionPayTest` — delete it here.)
- `tests/Feature/Finance/PayNowWalletOwnerTest.php:145` — same helper split (booking cases).
- `tests/Feature/Membership/PlanDiscountUsageTest.php:119` (booking)
- `tests/Feature/Pricing/CouponApplicationTest.php:75` — the purpose-switching helper becomes a `match ($purpose)` over the three operation requests above, returning the response; booking assertions take the booking response changes.
- `tests/Feature/Pricing/PlanCouponPurchaseTest.php:251` (plan)

- [ ] **Step 1: Apply the mapping, file by file**

After each file: `PHP artisan test <that file>` — Expected: PASS, with the same number of tests minus any deleted above.

- [ ] **Step 2: Prove nothing calls the old endpoint**

Run: `grep -rn "member/payments'" tests | grep -v "getJson"`
Expected: no output (only `GET member/payments` and `GET member/payments/{id}` remain).

- [ ] **Step 3: Run the moved suites together**

Run: `PHP artisan test tests/Feature/Finance tests/Feature/Pricing tests/Feature/Booking tests/Feature/Experience tests/Feature/Membership`
Expected: PASS except for any of the 17 pre-existing failures that live there.

- [ ] **Step 4: Style and commit**

```bash
PHP vendor/bin/pint --test tests/Feature
git add tests/Feature
git commit -m "test(payments): pay through each operation's endpoint, not POST member/payments

Every test that paid through the generic endpoint now pays where the app
will: the booking or registration sent again with its id, a session's pay,
a top-up, a plan purchase. Assertions unchanged; the cash top-up and the
purpose validation cases go with the endpoint.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Remove `POST member/payments`

**Files:**
- Modify: `routes/api/v1/member.php` (delete `Route::post('payments', ...)` and its comment block's "asks for a payment URL here" wording)
- Modify: `app/Http/Controllers/Api/V1/Member/PaymentController.php` (delete `store()`, `started()`, `planOutcome()` and the imports only they used; keep `index()` and `show()`; the trait becomes unused — drop it)
- Delete: `app/Http/Requests/Member/StartPaymentRequest.php`
- Modify: `app/Domain/Finance/Payments/MemberPayables.php` — delete if nothing else uses it (`grep -rn MemberPayables app`); `PaymentMethodsController` uses it → keep in that case
- Test: `tests/Feature/Finance/PaymentEndpointRemovedTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Finance;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Each operation pays through its own endpoint; the generic one is gone
 * (docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md).
 * What remains is reading: the member's payments, and one payment's status
 * for the app to poll after its WebView.
 */
class PaymentEndpointRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_generic_pay_endpoint_is_gone_and_the_reads_remain(): void
    {
        $this->seed(RoleSeeder::class);
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->postJson('/api/v1/member/payments', ['purpose' => 'wallet_top_up', 'amount' => '10'])
            ->assertStatus(405);

        $this->getJson('/api/v1/member/payments')->assertOk();
    }
}
```

- [ ] **Step 2: Run it**

Run: `PHP artisan test tests/Feature/Finance/PaymentEndpointRemovedTest.php`
Expected: FAIL — the POST answers 422/201, not 405.

- [ ] **Step 3: Delete the route, the action and the request** (files above).

- [ ] **Step 4: Run**

Run: `PHP artisan test tests/Feature/Finance/PaymentEndpointRemovedTest.php && PHP artisan route:list --path=member/payments`
Expected: PASS; the route list shows only `GET member/payments` and `GET member/payments/{payment}`.

- [ ] **Step 5: Full suite**

Run: `PHP artisan test`
Expected: exactly the 17 pre-existing failures, no others.

- [ ] **Step 6: Style and commit**

```bash
PHP vendor/bin/pint --test app/Http/Controllers/Api/V1/Member/PaymentController.php routes/api/v1/member.php tests/Feature/Finance/PaymentEndpointRemovedTest.php
git add -A app/Http/Controllers/Api/V1/Member/PaymentController.php app/Http/Requests/Member/StartPaymentRequest.php app/Domain/Finance/Payments/MemberPayables.php routes/api/v1/member.php tests/Feature/Finance/PaymentEndpointRemovedTest.php
git commit -m "feat(payments)!: remove POST member/payments

Each operation pays through its own endpoint now. GET member/payments and
GET member/payments/{id} stay: the history, and the status the app polls.

BREAKING CHANGE: the app tops up through POST member/wallet/top-ups, pays a
booking or registration by sending its request again with its id, and a
closed coworking session through coworking-sessions/{type}/{id}/pay.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Documentation

**Files:**
- Create: `docs/decisions/prepayment-all-or-nothing.md`
- Modify: `docs/decisions/README.md` (one line for the new decision)
- Modify: `docs/decisions/unified-payments.md` (methods table: booking/event rows — "then its card payment opens; to pay another way, send the request again with its id"; the "anything unpaid (`POST member/payments`)" row → "the operation's own endpoint"; top-up row → "— (reception)" for cash, "`POST member/wallet/top-ups`" for the card)
- Modify: `docs/paymera-mobile-api.md` §2 (top-up request is `POST /api/v1/member/wallet/top-ups` with `{amount}`; response unchanged) and §11 (ب: paying an existing booking = `POST member/bookings` + `booking_id` + `payment_method`; registration = `register` + `ticket_id`; closed session = `coworking-sessions/{type}/{id}/pay`; هـ "ادفع الآن" the same; remove every `POST member/payments` mention and the `purpose`/`payable_id` field table)
- Modify: `postman/ADD-OS.postman_collection.json` — remove "Start a Payment" and its examples; add "Top Up Wallet" (`POST {{base_url}}/api/v1/member/wallet/top-ups`, body `{"amount": "1470000"}`) and "Pay Coworking Session" (`POST {{base_url}}/api/v1/member/coworking-sessions/walkin/{{walkin_id}}/pay`, body `{"payment_method": "paymera"}`); add to Create Booking's and Register for Event's descriptions the id case; the Payments folder description says each operation pays through its own endpoint
- Modify: `docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md` — Endpoint map: the coworking `pay` and the top-up keep `POST member/payments`' answer shape (`{data: {payment, redirect_url, next}}`, 201); `already_paid` is 409

`docs/decisions/prepayment-all-or-nothing.md` content (English, the repo's decision-doc style — see `docs/decisions/reception-operations-scope.md` for the headings):

```markdown
# Prepaid methods are all-or-nothing; each operation pays through its own endpoint

**Status:** resolved 2026-10-06. **Owner:** Maryam Asha.

## Decision

- A booking or event registration is made first, then paid. To pay it
  another way — after a failed card, or later — the app sends the same
  create request again with the record's id (`booking_id`, `ticket_id`):
  nothing is created and no creation rule runs; only how it is paid
  changes.
- A closed coworking session is paid through
  `POST member/coworking-sessions/{type}/{id}/pay`; a member top-up through
  `POST member/wallet/top-ups`, by a prepaid method only (cash for points
  is reception's).
- `POST member/payments` is removed. `GET member/payments` and
  `GET member/payments/{id}` stay.
- Phase 2 (`docs/superpowers/plans/2026-10-06-prepayment-all-or-nothing.md`):
  a booking or registration made with a prepaid method is
  `awaiting_payment` for 15 minutes and is cancelled if it is not paid or
  switched to another method in time.

## Why

Two ways to pay the same booking (its own endpoint at creation, the
generic one afterwards) is duplication the app had to know about; the
confirm screen's retry with another method was refused because the
member's own unpaid booking held the slot; and a failed card left a
booking holding its slot for free. Reverses unified-payments' "a failed
card leaves it for the desk" (`BookingPaymentRouter::choose()`).

## What this changed in code

- `App\Domain\Finance\Payments\{PayableCheckout,CheckoutOutcome}`,
  `App\Http\Controllers\Concerns\AnswersPayments`
- `BookingController::store` (`booking_id`), `EventController::register`
  (`ticket_id`), `CoworkingSessionController::pay`, `TopUpController`
- `CashMethod::supports()` (no member top-up)
- removed: `PaymentController::store`, `StartPaymentRequest`
```

- [ ] **Step 1: Write the docs above.**
- [ ] **Step 2: Validate the Postman JSON**

Run: `PHP -r '$j = json_decode(file_get_contents("postman/ADD-OS.postman_collection.json"), true); echo $j === null ? "INVALID: ".json_last_error_msg() : "valid"; echo PHP_EOL;'`
Expected: `valid`

- [ ] **Step 3: Commit**

```bash
git add docs postman
git commit -m "docs(payments): each operation pays through its own endpoint

Decision doc for removing POST member/payments and the id re-send, the
unified-payments table pointed at it, the app guide and Postman updated.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
