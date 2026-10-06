# Prepaid Is All-or-Nothing — Implementation Plan (Phase 2 of 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A booking or event registration made with a prepaid method is held as `awaiting_payment` for 15 minutes and is cancelled if, by then, it is neither paid nor switched to another method.

**Architecture:** A new stored status `awaiting_payment` on `BookingStatus` and `EventRegistrationStatus` — holding the slot/seat, payable, but never `confirmed`/`approved`, so door codes, QR unlock and reception ignore it by default. A nullable `payment_due_at` column carries the deadline. One service, `PrepaymentHold`, computes the deadline and *releases* a hold (to `confirmed`/`pending`/`approved`) when it is paid or switched to a post-paid method; the fulfillers and `PayableCheckout` call it. A per-minute command cancels expired holds after one last gateway check.

**Tech Stack:** Laravel 12, PHP 8.3, PHPUnit feature tests on in-memory SQLite, Laravel Pint.

**Spec:** `docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md` ("Delivery → 2. All-or-nothing"). **Requires Phase 1** (`docs/superpowers/plans/2026-10-06-one-way-to-pay.md`) merged first: `PayableCheckout`, `AnswersPayments`, the `booking_id` / `ticket_id` id branches.

## Global Constraints

- PHP is `/c/laragon/bin/php/php-8.3.33-nts-Win32-vs16-x64/php.exe` (written `PHP` below).
- The full suite has **17 pre-existing failures**; a task adds none.
- The window: `config('payments.prepayment_window_minutes')`, env `PAYMENTS_PREPAYMENT_WINDOW_MINUTES`, default `15`. Deadline = `min(now + window, start)`.
- Prepaid method = any key in `PaymentMethodRegistry::gatewayKeys()`; never compare to the string `'paymera'`.
- `payment_due_at` is set when a hold starts, **cleared when it is released** (paid / switched), and **kept when it is cancelled** — so "cancelled with a `payment_due_at`" means "cancelled because its prepayment did not complete". (This corrects the spec's "never cleared": a hold that was paid and later cancelled must not read as unpaid. Task 9 updates the spec.)
- Copy goes in both `lang/en/api.php` and `lang/ar/api.php`.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **A card accepted in the minute between the deadline and the job** — the hold is paid and released, not cancelled (Task 8's last check pins it; Task 3 pins that the fulfiller releases regardless of the clock).
2. **A hold paid, then cancelled by the member** must show an ordinary cancellation, not "payment not completed" (Task 2 pins `cancelledAwaitingPayment()` against a released hold).
3. **A space that needs approval** — the operations notice is sent when the hold is released, never at creation and never twice (Task 3).
4. **A member going back and changing the time** — their own overlapping hold is cancelled first; another member's booking, or their own real booking, still blocks (Task 4).
5. **The booking stepper for `awaiting_payment` and for a cancelled hold** — no `UnhandledMatchError`, never a "Confirmed" step that did not happen (Task 1).

---

### Task 1: The `awaiting_payment` status

**Files:**
- Modify: `app/Domain/Booking/Enums/BookingStatus.php`
- Modify: `app/Support/Enums/EventRegistrationStatus.php`
- Modify: `app/Domain/Booking/Services/BookingPresentationService.php` (stepper, `statusNote()`)
- Modify: `app/Domain/Experience/Services/EventBrowseService.php` (`translateRegistrationStatus()`, the registration-state block)
- Modify: `app/Domain/Booking/Models/Booking.php` (`cancelledAwaitingPayment()`)
- Modify: `lang/en/api.php`, `lang/ar/api.php` (`booking.statuses.awaiting_payment`, `booking.status_notes.payment_not_completed`)
- Test: `tests/Unit/Domain/Booking/AwaitingPaymentStatusTest.php`

**Interfaces:**
- Produces: `BookingStatus::AwaitingPayment` (first case) in `open()`, `slotHolding()`, `payable()`; `EventRegistrationStatus::AwaitingPayment` (first case) in `seatHolding()`, not `isTerminal()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Support\Enums\EventRegistrationStatus;
use PHPUnit\Framework\TestCase;

/**
 * A booking or registration held for its prepayment holds its place and can
 * be paid or cancelled — but is never confirmed/approved, so everything that
 * treats those as real ignores it by default.
 */
class AwaitingPaymentStatusTest extends TestCase
{
    public function test_a_booking_awaiting_payment_holds_its_slot_and_can_be_paid_and_cancelled(): void
    {
        $this->assertSame('awaiting_payment', BookingStatus::AwaitingPayment->value);
        $this->assertSame(BookingStatus::AwaitingPayment, BookingStatus::cases()[0]);
        $this->assertContains(BookingStatus::AwaitingPayment, BookingStatus::slotHolding());
        $this->assertTrue(BookingStatus::AwaitingPayment->isPayable());
        $this->assertTrue(BookingStatus::AwaitingPayment->isOpen());
    }

    public function test_a_registration_awaiting_payment_holds_its_seat_and_is_not_decided(): void
    {
        $this->assertSame('awaiting_payment', EventRegistrationStatus::AwaitingPayment->value);
        $this->assertContains(EventRegistrationStatus::AwaitingPayment, EventRegistrationStatus::seatHolding());
        $this->assertTrue(EventRegistrationStatus::AwaitingPayment->holdsSeat());
        $this->assertFalse(EventRegistrationStatus::AwaitingPayment->isTerminal());
    }
}
```

- [ ] **Step 2: Run it** — `PHP artisan test tests/Unit/Domain/Booking/AwaitingPaymentStatusTest.php` → FAIL (undefined case).

- [ ] **Step 3: The enums**

`BookingStatus` — add as the first case and to three lists:

```php
    // Made with a prepaid method, held for its payment until
    // `payment_due_at`: it holds the slot and can be paid or cancelled, but
    // it is not confirmed — door codes, QR unlock and reception ignore it
    // (docs/decisions/prepayment-all-or-nothing.md).
    case AwaitingPayment = 'awaiting_payment';
```
```php
    /** Still to happen: held for its payment, awaiting approval, or confirmed. */
    public static function open(): array
    {
        return [self::AwaitingPayment, self::Pending, self::Confirmed];
    }
```
`slotHolding()` → `[self::AwaitingPayment, self::Pending, self::Confirmed, self::Completed, self::NoShow]`; `payable()` → `[self::AwaitingPayment, self::Pending, self::Confirmed, self::Completed]`.

`EventRegistrationStatus` — first case `case AwaitingPayment = 'awaiting_payment';` (same comment, "seat"); `seatHolding()` → `[self::AwaitingPayment, self::Pending, self::Approved]`; `isTerminal()` → `return ! in_array($this, [self::AwaitingPayment, self::Pending], true);`.

- [ ] **Step 4: Labels**

`lang/en/api.php`, `booking.statuses`: `'awaiting_payment' => 'Awaiting Payment',`; `booking.status_notes`: `'payment_not_completed' => 'Cancelled because the payment was not completed in time.',`
`lang/ar/api.php`, `booking.statuses`: `'awaiting_payment' => 'بانتظار الدفع',`; `booking.status_notes`: `'payment_not_completed' => 'أُلغي لأن الدفع لم يكتمل في الوقت المحدد.',`

`EventBrowseService::translateRegistrationStatus()` — before the `rejected` branch:
```php
        if ($status === 'awaiting_payment') {
            return $locale === 'ar' ? 'بانتظار الدفع' : 'Awaiting payment';
        }
```
and in the registration-state block, before `if ($attendee?->status === EventRegistrationStatus::Pending)`:
```php
        if ($attendee?->status === EventRegistrationStatus::AwaitingPayment) {
            $regStatus = 'awaiting_payment';
            $regLabel = $locale === 'ar' ? 'بانتظار الدفع' : 'Awaiting payment';
        } elseif ($attendee?->status === EventRegistrationStatus::Pending) {
```
(turning the existing `if` into `elseif`).

- [ ] **Step 5: The stepper and the note**

First add `Booking::cancelledAwaitingPayment()` now (it reads `payment_due_at`, which is simply null until Task 2 adds the column):

```php
    /**
     * Cancelled while still held for its prepayment — by the clock, by the
     * member backing out, or superseded. A released hold has no deadline
     * left, so a paid booking cancelled later is not this.
     */
    public function cancelledAwaitingPayment(): bool
    {
        return $this->status === BookingStatus::Cancelled && $this->payment_due_at !== null;
    }
```

In `BookingPresentationService`, right after `$outcome = $this->outcomeStep($booking, $locale);`:

```php
        // Held for its prepayment, or cancelled while still held: it was
        // never confirmed, whatever the space's approval flag says.
        if ($booking->status === BookingStatus::AwaitingPayment || $booking->cancelledAwaitingPayment()) {
            return [
                'steps' => array_values(array_filter([
                    $submitted,
                    $step(BookingStatus::AwaitingPayment, $booking->status === BookingStatus::AwaitingPayment ? 'current' : 'complete', $booking->created_at),
                    $booking->status === BookingStatus::Cancelled
                        ? $step(BookingStatus::Cancelled, 'complete', $booking->cancelled_at)
                        : $step($booking->required_approval ? BookingStatus::Pending : BookingStatus::Confirmed, 'upcoming', null),
                ])),
                'rejection_reason' => null,
            ];
        }
```
`statusNote()` — replace the `Cancelled, Rejected` arm:
```php
            BookingStatus::Cancelled, BookingStatus::Rejected => match (true) {
                $booking->cancelledAwaitingPayment() => 'payment_not_completed',
                $paid && $this->wasRefunded($booking) => 'refunded',
                default => null,
            },
```

- [ ] **Step 6: Run** — `PHP artisan test tests/Unit/Domain/Booking tests/Feature/Booking tests/Feature/Experience` → PASS (minus pre-existing). The enum guard (`tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`) must stay at its pre-existing state.

- [ ] **Step 7: Commit**

```bash
PHP vendor/bin/pint --test app/Domain/Booking/Enums/BookingStatus.php app/Support/Enums/EventRegistrationStatus.php app/Domain/Booking/Services/BookingPresentationService.php app/Domain/Experience/Services/EventBrowseService.php tests/Unit/Domain/Booking/AwaitingPaymentStatusTest.php
git add app/Domain/Booking/Enums/BookingStatus.php app/Support/Enums/EventRegistrationStatus.php app/Domain/Booking/Services/BookingPresentationService.php app/Domain/Experience/Services/EventBrowseService.php lang/en/api.php lang/ar/api.php tests/Unit/Domain/Booking/AwaitingPaymentStatusTest.php
git commit -m "feat(payments): an awaiting_payment status for bookings and registrations

Holds the slot or seat and can be paid or cancelled, but is never
confirmed/approved, so door codes, QR unlock and reception ignore it.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `payment_due_at`, the window, and the model helpers

**Files:**
- Create: `database/migrations/2026_10_06_120000_add_payment_due_at_to_bookings_and_event_attendees.php`
- Modify: `app/Domain/Booking/Models/Booking.php` (fillable, cast, two helpers)
- Modify: `app/Domain/Ecosystem/Models/EventAttendee.php` (fillable, cast, one helper)
- Modify: `config/payments.php`, `.env.example`
- Test: `tests/Feature/Booking/PrepaymentHoldModelTest.php`

**Interfaces:**
- Produces: `Booking::awaitingPaymentExpired(): bool`, `Booking::cancelledAwaitingPayment(): bool`, `EventAttendee::awaitingPaymentExpired(): bool`; `config('payments.prepayment_window_minutes')`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrepaymentHoldModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_hold_past_its_deadline_has_expired(): void
    {
        $hold = Booking::factory()->create(['status' => BookingStatus::AwaitingPayment, 'payment_due_at' => now()->subMinute()]);
        $live = Booking::factory()->create(['status' => BookingStatus::AwaitingPayment, 'payment_due_at' => now()->addMinute()]);

        $this->assertTrue($hold->awaitingPaymentExpired());
        $this->assertFalse($live->awaitingPaymentExpired());
    }

    /**
     * A released hold has no deadline left, so cancelling it later reads as
     * an ordinary cancellation — not "payment not completed".
     */
    public function test_only_a_hold_cancelled_while_held_reads_as_unpaid(): void
    {
        $cancelledWhileHeld = Booking::factory()->create(['status' => BookingStatus::Cancelled, 'payment_due_at' => now()->subMinute()]);
        $paidThenCancelled = Booking::factory()->create(['status' => BookingStatus::Cancelled, 'payment_due_at' => null]);

        $this->assertTrue($cancelledWhileHeld->cancelledAwaitingPayment());
        $this->assertFalse($paidThenCancelled->cancelledAwaitingPayment());
    }

    public function test_the_window_defaults_to_fifteen_minutes(): void
    {
        $this->assertSame(15, config('payments.prepayment_window_minutes'));
    }
}
```

- [ ] **Step 2: Run** → FAIL (no column / method / config).

- [ ] **Step 3: Migration, models, config**

Migration:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The deadline of a booking or registration held for its prepayment
 * (docs/decisions/prepayment-all-or-nothing.md): set when the hold starts,
 * cleared when it is released, kept when it is cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('payment_due_at')->nullable()->index();
        });

        Schema::table('event_attendees', function (Blueprint $table) {
            $table->timestamp('payment_due_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['payment_due_at']);
            $table->dropColumn('payment_due_at');
        });

        Schema::table('event_attendees', function (Blueprint $table) {
            $table->dropIndex(['payment_due_at']);
            $table->dropColumn('payment_due_at');
        });
    }
};
```
`Booking`: add `'payment_due_at'` to `$fillable`, `'payment_due_at' => 'datetime'` to `casts()`, and:
```php
    /** Held for its prepayment past the deadline: no new payment may open. */
    public function awaitingPaymentExpired(): bool
    {
        return $this->status === BookingStatus::AwaitingPayment
            && $this->payment_due_at !== null
            && ! $this->payment_due_at->isFuture();
    }
```
(`cancelledAwaitingPayment()` was added in Task 1.)
`EventAttendee`: `'payment_due_at'` in `$fillable`, `'payment_due_at' => 'datetime'` in `casts()`, and the same `awaitingPaymentExpired()` with `EventRegistrationStatus::AwaitingPayment`.

`config/payments.php`, after `min_top_up_points`:
```php
    /*
    |--------------------------------------------------------------------------
    | Prepayment window
    |--------------------------------------------------------------------------
    |
    | A booking or registration made with a prepaid method (a gateway) is
    | held this many minutes — never past its start — for the card to go
    | through or the member to choose another method; then it is cancelled
    | (payments:cancel-expired-prepayments).
    |
    */
    'prepayment_window_minutes' => (int) env('PAYMENTS_PREPAYMENT_WINDOW_MINUTES', 15),
```
`.env.example`: `PAYMENTS_PREPAYMENT_WINDOW_MINUTES=15` beside the other `PAYMENTS_` keys.

- [ ] **Step 4: Run** — `PHP artisan test tests/Feature/Booking/PrepaymentHoldModelTest.php` → PASS.

- [ ] **Step 5: Commit**

```bash
PHP vendor/bin/pint --test database/migrations/2026_10_06_120000_add_payment_due_at_to_bookings_and_event_attendees.php app/Domain/Booking/Models/Booking.php app/Domain/Ecosystem/Models/EventAttendee.php config/payments.php tests/Feature/Booking/PrepaymentHoldModelTest.php
git add database/migrations/2026_10_06_120000_add_payment_due_at_to_bookings_and_event_attendees.php app/Domain/Booking/Models/Booking.php app/Domain/Ecosystem/Models/EventAttendee.php config/payments.php .env.example tests/Feature/Booking/PrepaymentHoldModelTest.php
git commit -m "feat(payments): payment_due_at and a 15-minute prepayment window

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `PrepaymentHold` — releasing a hold, from the fulfillers and the checkout

**Files:**
- Create: `app/Domain/Booking/Services/PendingBookingNotice.php` (extracted from `BookingCreationService::notifyOperationsOfPendingBooking()`)
- Create: `app/Domain/Finance/Payments/PrepaymentHold.php`
- Modify: `app/Domain/Booking/Services/BookingCreationService.php` (use `PendingBookingNotice`)
- Modify: `app/Domain/Finance/Payments/Fulfillers/BookingFulfiller.php` (`fulfil()` releases)
- Modify: `app/Domain/Finance/Payments/Fulfillers/EventRegistrationFulfiller.php` (`fulfil()` releases; `assertPayable()` accepts `AwaitingPayment`)
- Modify: `app/Domain/Finance/Payments/PayableCheckout.php` (refuse an expired hold; release on a post-paid method)
- Test: `tests/Feature/Finance/PrepaymentHoldTest.php`

**Interfaces:**
- Consumes: Task 1, Task 2; Phase 1's `PayableCheckout`.
- Produces:
  - `PendingBookingNotice::send(Booking $booking): void`
  - `PrepaymentHold::dueAt(CarbonInterface $start): CarbonInterface`
  - `PrepaymentHold::release(Booking|EventAttendee $record): void` — idempotent; `awaiting_payment` → `confirmed` (`pending` when `required_approval`, which also sends the notice) / `approved`; clears `payment_due_at`.

- [ ] **Step 1: Failing test**

`tests/Feature/Finance/PrepaymentHoldTest.php` — `setUp()`, `member()`, `walletOf()`, `fakeGateway()` copied from `tests/Feature/Finance/PayNowTest.php`, plus:

```php
    private function hold(User $member, bool $requiresApproval = false): Booking
    {
        return Booking::factory()->create([
            'user_id' => $member->id,
            'status' => BookingStatus::AwaitingPayment,
            'required_approval' => $requiresApproval,
            'payment_due_at' => now()->addMinutes(15),
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(3),
            'payment_state' => PaymentState::Unpaid,
            'price_points' => 100,
        ]);
    }

    public function test_paying_a_hold_by_card_confirms_it(): void
    {
        $member = $this->member();
        $hold = $this->hold($member);
        $this->fakeGateway();

        app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $hold, $member, 'paymera');
        $this->assertSame(BookingStatus::AwaitingPayment, $hold->refresh()->status);

        app(SyncPaymentStatus::class)->handle(Payment::sole());

        $hold->refresh();
        $this->assertSame(BookingStatus::Confirmed, $hold->status);
        $this->assertNull($hold->payment_due_at);
    }

    public function test_a_hold_on_a_space_needing_approval_goes_to_review_and_staff_hear_of_it_then(): void
    {
        $member = $this->member(300);
        User::factory()->create()->assignRole('operations');
        $hold = $this->hold($member, requiresApproval: true);

        $this->assertSame(0, NotificationLog::where('template_key', 'booking.pending_approval')->count());

        app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $hold, $member, 'wallet');

        $this->assertSame(BookingStatus::Pending, $hold->refresh()->status);
        $this->assertSame(1, NotificationLog::where('template_key', 'booking.pending_approval')->count());
    }

    public function test_choosing_cash_releases_the_hold_unpaid(): void
    {
        $member = $this->member();
        $hold = $this->hold($member);

        app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $hold, $member, 'cash');

        $hold->refresh();
        $this->assertSame(BookingStatus::Confirmed, $hold->status);
        $this->assertSame(PaymentState::Unpaid, $hold->payment_state);
        $this->assertNull($hold->payment_due_at);
    }

    public function test_no_new_payment_opens_on_an_expired_hold(): void
    {
        $member = $this->member(300);
        $hold = $this->hold($member);
        $hold->forceFill(['payment_due_at' => now()->subMinute()])->save();

        $this->expectException(ReceptionActionException::class);

        app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $hold, $member, 'wallet');
    }

    /**
     * Past the deadline but before the job ran: a card already in flight
     * that the bank accepts pays the hold in full — nothing to undo.
     */
    public function test_a_card_accepted_after_the_deadline_still_releases_the_hold(): void
    {
        $member = $this->member();
        $hold = $this->hold($member);
        $this->fakeGateway();
        app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $hold, $member, 'paymera');

        Carbon::setTestNow(now()->addMinutes(16));
        app(SyncPaymentStatus::class)->handle(Payment::sole());

        $this->assertSame(BookingStatus::Confirmed, $hold->refresh()->status);
    }
```
(imports: `BookingStatus`, `PaymentState`, `Booking`, `PaymentPurpose`, `Payment`, `PayableCheckout`, `SyncPaymentStatus`, `ReceptionActionException`, `App\Domain\Identity\Models\NotificationLog`, `User`, `Carbon`.)

- [ ] **Step 2: Run** → FAIL (stays `awaiting_payment`; cash leaves it held; the expired one pays).

- [ ] **Step 3: `PendingBookingNotice`**

```php
<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;

/**
 * Tells operations a booking waits for their review. Sent when a booking
 * reaches `pending` — at creation, or when its prepayment hold is released
 * (PrepaymentHold) — never while it may still vanish unpaid.
 *
 * NotificationLog rows only — no real send channel exists for staff yet;
 * channel 'push', status 'sent' means "generated by this system".
 */
class PendingBookingNotice
{
    public function send(Booking $booking): void
    {
        User::role(['operations', 'admin'])->get()->each(function (User $recipient) {
            NotificationLog::create([
                'user_id' => $recipient->id,
                'channel' => 'push',
                'template_key' => 'booking.pending_approval',
                'status' => 'sent',
            ]);
        });
    }
}
```
In `BookingCreationService`: inject `private readonly PendingBookingNotice $pendingNotice`, replace `$this->notifyOperationsOfPendingBooking($booking)` with `$this->pendingNotice->send($booking)`, delete the private method.

- [ ] **Step 4: `PrepaymentHold`**

```php
<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\PendingBookingNotice;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Support\Enums\EventRegistrationStatus;
use Carbon\CarbonInterface;

/**
 * A booking or registration made with a prepaid method is held — status
 * `awaiting_payment` — until its card goes through or the member chooses
 * another method; otherwise it is cancelled at `payment_due_at`
 * (docs/decisions/prepayment-all-or-nothing.md).
 */
class PrepaymentHold
{
    public function __construct(private readonly PendingBookingNotice $pendingNotice) {}

    /** When a hold made now ends: the window, never past the start. */
    public function dueAt(CarbonInterface $start): CarbonInterface
    {
        $due = now()->addMinutes((int) config('payments.prepayment_window_minutes', 15));

        return $due->lessThan($start) ? $due : $start->copy();
    }

    /**
     * How it is paid is settled — paid, or a method paid later chosen: the
     * hold becomes what the operation would have been without it, and its
     * deadline goes. Anything not held is left alone, so every caller may
     * call this unconditionally.
     */
    public function release(Booking|EventAttendee $record): void
    {
        if ($record instanceof Booking && $record->status === BookingStatus::AwaitingPayment) {
            $status = $record->required_approval ? BookingStatus::Pending : BookingStatus::Confirmed;
            $record->forceFill(['status' => $status, 'payment_due_at' => null])->save();

            if ($status === BookingStatus::Pending) {
                $this->pendingNotice->send($record);
            }

            return;
        }

        if ($record instanceof EventAttendee && $record->status === EventRegistrationStatus::AwaitingPayment) {
            $record->forceFill(['status' => EventRegistrationStatus::Approved, 'payment_due_at' => null])->save();
        }
    }
}
```

- [ ] **Step 5: The fulfillers release**

`BookingFulfiller`: add `private readonly PrepaymentHold $holds` to the constructor and:
```php
    /**
     * Paid — and a booking held for this payment is no longer held.
     */
    public function fulfil(Payment $payment): void
    {
        parent::fulfil($payment);

        $this->holds->release($this->session($payment));
    }
```
`EventRegistrationFulfiller`: inject `PrepaymentHold $holds`; `fulfil()` becomes:
```php
    public function fulfil(Payment $payment): void
    {
        $attendee = $this->attendee($payment);

        $attendee->forceFill([
            'payment_state' => EventPaymentState::Paid,
            'paid_at' => now(),
        ])->save();

        $this->holds->release($attendee);
    }
```
and `assertPayable()`'s first check:
```php
        if (! in_array($attendee->status, [EventRegistrationStatus::Approved, EventRegistrationStatus::AwaitingPayment], true)) {
```

- [ ] **Step 6: `PayableCheckout` refuses an expired hold and releases on a post-paid method**

Inject `private readonly PrepaymentHold $holds, private readonly PaymentMethodRegistry $methods`. At the top of `pay()`:
```php
        // A hold past its deadline takes no new payment: it is about to be
        // cancelled (payments:cancel-expired-prepayments). A card already in
        // flight still settles it — that goes through the fulfiller, not here.
        if (($payable instanceof Booking || $payable instanceof EventAttendee) && $payable->awaitingPaymentExpired()) {
            throw new ReceptionActionException($payable instanceof Booking ? 'api.payment.booking_not_payable' : 'api.payment.registration_not_payable');
        }
```
In the coupon-made-it-free branch, before `return new CheckoutOutcome(null, PaymentStart::done(), $outcome);` add `$this->releaseHold($payable);`. After `$this->payments->start(...)`, before the return:
```php
        // A method paid later (cash) settles how it is paid without paying
        // it: the hold goes now. The wallet already released it through the
        // fulfiller; a gateway keeps it held until the bank answers.
        if (! in_array($method, $this->methods->gatewayKeys(), true)) {
            $this->releaseHold($payable);
        }
```
and:
```php
    private function releaseHold(Model $payable): void
    {
        if ($payable instanceof Booking || $payable instanceof EventAttendee) {
            $this->holds->release($payable->refresh());
        }
    }
```
(imports: `Booking`, `EventAttendee`, `ReceptionActionException`, `PaymentMethodRegistry`.)

- [ ] **Step 7: Adjust one Phase 1 test the hold makes obsolete**

`tests/Feature/Booking/BookingPayByIdTest.php::test_sent_again_close_to_the_start_is_not_refused_as_too_soon` makes its booking with `paymera` at 08:00 and pays it at 09:30. Once Task 4 makes that a hold, it has expired by 09:30 and Step 6 refuses it — on purpose. The case the test pins (no creation rule on the id path) is about paying later, so make the first booking with `'payment_method' => 'cash'` (an ordinary unpaid booking, no deadline) and keep the rest.

- [ ] **Step 8: Run** — `PHP artisan test tests/Feature/Finance/PrepaymentHoldTest.php tests/Feature/Finance tests/Feature/Booking tests/Feature/Experience` → PASS (minus pre-existing).

- [ ] **Step 9: Commit**

```bash
PHP vendor/bin/pint --test app/Domain/Booking/Services/PendingBookingNotice.php app/Domain/Finance/Payments/PrepaymentHold.php app/Domain/Booking/Services/BookingCreationService.php app/Domain/Finance/Payments/Fulfillers/BookingFulfiller.php app/Domain/Finance/Payments/Fulfillers/EventRegistrationFulfiller.php app/Domain/Finance/Payments/PayableCheckout.php tests/Feature/Finance/PrepaymentHoldTest.php
git add app/Domain/Booking/Services/PendingBookingNotice.php app/Domain/Finance/Payments/PrepaymentHold.php app/Domain/Booking/Services/BookingCreationService.php app/Domain/Finance/Payments/Fulfillers/BookingFulfiller.php app/Domain/Finance/Payments/Fulfillers/EventRegistrationFulfiller.php app/Domain/Finance/Payments/PayableCheckout.php tests/Feature/Finance/PrepaymentHoldTest.php
git commit -m "feat(payments): a paid or switched hold is released

PrepaymentHold moves an awaiting_payment booking to confirmed (or pending,
telling operations then) and a registration to approved, from the
fulfillers and when cash is chosen. An expired hold takes no new payment;
a card already in flight still settles it.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: A booking made with a prepaid method is a hold; overlapping holds of the member are superseded

**Files:**
- Modify: `app/Domain/Booking/Services/BookingCreationService.php`
- Modify: `app/Domain/Booking/Services/BookingCancellationService.php` (`releaseUnpaidHold()` — also used by Task 8)
- Modify: `app/Domain/Booking/Services/BookingPaymentRouter.php` (comment of the gateway arm)
- Modify: `app/Http/Resources/BookingResource.php` (`payment_due_at`)
- Test: `tests/Feature/Booking/PrepaidBookingHoldTest.php`

**Interfaces:**
- Consumes: Task 3 (`PrepaymentHold::dueAt()`).
- Produces: `BookingCancellationService::releaseUnpaidHold(Booking $booking): bool` — cancels a booking still `awaiting_payment` (no window check; refund/discount/coupon give-back as a cancellation does); false when it is no longer held. `BookingResource` gains `payment_due_at` (ISO-8601 or null).

- [ ] **Step 1: Failing test**

`tests/Feature/Booking/PrepaidBookingHoldTest.php` — `setUp()`, `member()`, `openSpace()`, `book()` copied from Phase 1's `tests/Feature/Booking/BookingPayByIdTest.php` (08:00 Monday, Paymera config), plus `Http::fake(['*' => Http::response('', 500)])` in each card case so no real call is made, then:

```php
    public function test_a_card_booking_is_held_until_its_deadline(): void
    {
        $this->member();
        $space = $this->openSpace();
        Http::fake(['*' => Http::response('', 500)]);

        $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_method' => 'paymera',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_payment')
            // BookingResource renders times in UTC, as start_at/end_at.
            ->assertJsonPath('data.payment_due_at', Carbon::parse('2026-08-17 08:15:00', 'Asia/Damascus')->utc()->toIso8601String());
    }

    /**
     * A window longer than the time left before the start ends at the
     * start: made at 08:00 for 10:00 with a three-hour window.
     */
    public function test_the_deadline_never_passes_the_start(): void
    {
        $this->member();
        $space = $this->openSpace();
        config(['payments.prepayment_window_minutes' => 180]);
        Http::fake(['*' => Http::response('', 500)]);

        $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_method' => 'paymera',
        ])->assertJsonPath('data.payment_due_at', Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc()->toIso8601String());
    }

    /**
     * Door codes go to confirmed bookings only: a hold on its own day gets
     * none (access:issue-grants-on-cancellation-window-close).
     */
    public function test_a_hold_gets_no_door_code(): void
    {
        $this->member();
        $space = $this->openSpace();
        Http::fake(['*' => Http::response('', 500)]);
        $id = $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_method' => 'paymera',
        ])->json('data.id');

        $this->artisan('access:issue-grants-on-cancellation-window-close')->assertSuccessful();

        $this->assertSame(0, Booking::findOrFail($id)->accessGrants()->count());
    }

    public function test_wallet_and_cash_bookings_are_not_held(): void
    {
        $this->member(500);
        $space = $this->openSpace();

        $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_method' => 'cash',
        ])->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.payment_due_at', null);
    }

    public function test_going_back_and_choosing_another_time_lets_the_members_own_hold_go(): void
    {
        $this->member();
        $space = $this->openSpace();
        Http::fake(['*' => Http::response('', 500)]);
        $first = $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T12:00:00+03:00',
            'payment_method' => 'paymera',
        ])->json('data.id');

        $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T11:00:00+03:00',
            'end_at' => '2026-08-17T12:00:00+03:00',
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->assertSame(BookingStatus::Cancelled, Booking::findOrFail($first)->status);
        $this->assertTrue(Booking::findOrFail($first)->cancelledAwaitingPayment());
    }

    public function test_the_members_own_real_booking_is_never_superseded(): void
    {
        $this->member(500);
        $space = $this->openSpace();
        $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T12:00:00+03:00',
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T11:00:00+03:00',
            'end_at' => '2026-08-17T12:00:00+03:00',
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }
```
- [ ] **Step 2: Run** → FAIL (`confirmed`, no `payment_due_at`; superseding refused as slot unavailable).

- [ ] **Step 3: `releaseUnpaidHold()` and the member's cancel of a hold**

In `BookingCancellationService`:
- `canCancel()`: after the `checked_in_at` check, add `if ($booking->status === BookingStatus::AwaitingPayment) { return true; }` — nothing was paid, so the window does not apply.
- in `cancel()`, the window check becomes `if ($locked->status !== BookingStatus::AwaitingPayment && now()->gt($locked->start_at->copy()->subMinutes($windowMinutes)))`.
- extract the write into a private method and add the system path:

```php
    /**
     * A hold let go by the system — its deadline passed, or the member
     * superseded it with another time. No cancellation window applies
     * (nothing was paid) and a hold already released is left alone.
     *
     * @return bool whether it was cancelled
     */
    public function releaseUnpaidHold(Booking $booking): bool
    {
        return DB::transaction(function () use ($booking) {
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== BookingStatus::AwaitingPayment) {
                return false;
            }

            $this->markCancelled($locked);

            return true;
        });
    }

    private function markCancelled(Booking $locked): void
    {
        $locked->forceFill([
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();

        $this->refunder->refund($locked, 'Booking cancellation refund');

        // The usage this booking consumed is returned once it no
        // longer holds a charge (docs/decisions/plan-discounts-design.md).
        $this->discounts->voidFor($locked);
    }
```
and `cancel()` calls `$this->markCancelled($locked);` in place of the three statements it had.

- [ ] **Step 4: Creation makes a hold and supersedes**

In `BookingCreationService`, inject `private readonly PaymentMethodRegistry $methods, private readonly PrepaymentHold $holds, private readonly BookingCancellationService $cancellations`.

Inside the transaction, right after `$this->assertAcceptsBookings($locked);`:
```php
            // A member who went back from the confirm screen and picked
            // another time would be refused by their own hold: holds of
            // theirs on this space that this window would collide with are
            // let go first. Only a hold — a real booking still blocks.
            $padding = max($rules->bufferMinutes, 0);
            Booking::query()
                ->forSpace($locked->id)
                ->where('user_id', $member->id)
                ->where('status', BookingStatus::AwaitingPayment)
                ->overlapping($startAt->copy()->subMinutes($padding), $endAt->copy()->addMinutes($padding))
                ->get()
                ->each(fn (Booking $hold) => $this->cancellations->releaseUnpaidHold($hold));
```
Replace the status line and the `Booking::create` fields:
```php
            // A prepaid method pays after the booking exists: it is held
            // until the card goes through, the member chooses another
            // method, or its deadline (docs/decisions/prepayment-all-or-nothing.md).
            $held = $finalPoints > 0
                && $paymentMethod !== null
                && in_array($paymentMethod, $this->methods->gatewayKeys(), true);

            $status = match (true) {
                $held => BookingStatus::AwaitingPayment,
                (bool) $locked->requires_approval => BookingStatus::Pending,
                default => BookingStatus::Confirmed,
            };
```
```php
                'status' => $status,
                'required_approval' => (bool) $locked->requires_approval,
                'payment_due_at' => $held ? $this->holds->dueAt($startAt) : null,
```
(`required_approval` was `$status === BookingStatus::Pending`, which equalled the space's flag before a hold existed; now it must be read from the space so a released hold knows where to go.) The `if ($status === BookingStatus::Pending)` notice stays — a hold is not `pending`, so staff are told on release (Task 3).

`BookingPaymentRouter::choose()`, the gateway arm's comment becomes: `// A gateway pays after the booking exists: it is held (awaiting_payment) until the card goes through, another method is chosen, or its deadline.`

- [ ] **Step 5: `payment_due_at` in `BookingResource`**

```php
            'payment_due_at' => $this->payment_due_at?->toIso8601String(),
```

- [ ] **Step 6: Run** — `PHP artisan test tests/Feature/Booking/PrepaidBookingHoldTest.php tests/Feature/Booking` → PASS (minus pre-existing).

- [ ] **Step 7: Commit**

```bash
PHP vendor/bin/pint --test app/Domain/Booking/Services/BookingCreationService.php app/Domain/Booking/Services/BookingCancellationService.php app/Domain/Booking/Services/BookingPaymentRouter.php app/Http/Resources/BookingResource.php tests/Feature/Booking/PrepaidBookingHoldTest.php
git add app/Domain/Booking/Services/BookingCreationService.php app/Domain/Booking/Services/BookingCancellationService.php app/Domain/Booking/Services/BookingPaymentRouter.php app/Http/Resources/BookingResource.php tests/Feature/Booking/PrepaidBookingHoldTest.php
git commit -m "feat(bookings): a card booking is held for its payment, not confirmed

Made awaiting_payment with a payment_due_at 15 minutes ahead (never past
its start). A member's own overlapping hold is let go when they pick
another time; a hold can be cancelled inside the cancellation window.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Check-in refuses a booking still held for its payment

**Files:**
- Modify: `app/Domain/Booking/Services/BookingCheckInService.php`
- Modify: `lang/en/api.php`, `lang/ar/api.php` (`booking.check_in_awaiting_payment`)
- Test: add to `tests/Feature/Booking/BookingCheckInServiceTest.php`

- [ ] **Step 1: Failing test** (append to `BookingCheckInServiceTest`, using that file's own booking helper):

```php
    public function test_a_booking_held_for_its_payment_cannot_be_checked_in(): void
    {
        $booking = $this->bookingStartingSoon(['status' => BookingStatus::AwaitingPayment, 'payment_due_at' => now()->addMinutes(10)]);

        try {
            app(BookingCheckInService::class)->checkIn($booking);
            $this->fail('Checked in a booking still held for its payment');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.check_in_awaiting_payment', $e->messageKey);
        }
    }
```
(`bookingStartingSoon()`: the helper that file already uses to build a check-in-able booking — reuse it, passing the overrides.)

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: The check**, first in `checkIn()`:
```php
        // Held for its card payment: take the payment first (reception
        // settles it, which releases the hold), then check it in.
        if ($booking->status === BookingStatus::AwaitingPayment) {
            throw new ReceptionActionException('api.booking.check_in_awaiting_payment', 409);
        }
```
`lang/en/api.php` `booking`: `'check_in_awaiting_payment' => 'This booking is waiting for its payment. Take the payment first, then check it in.',`
`lang/ar/api.php` `booking`: `'check_in_awaiting_payment' => 'هذا الحجز بانتظار الدفع. حصّل الدفع أولاً ثم سجّل دخوله.',`

- [ ] **Step 4: Run** — `PHP artisan test tests/Feature/Booking/BookingCheckInServiceTest.php` → PASS (plus its pre-existing failure, if any).

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Booking/Services/BookingCheckInService.php lang/en/api.php lang/ar/api.php tests/Feature/Booking/BookingCheckInServiceTest.php
git commit -m "feat(reception): a booking held for its payment is paid before check-in

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Event registrations approved on the spot with a prepaid method are holds

**Files:**
- Modify: `app/Services/Experience/EventRegistrationService.php`
- Modify: `app/Http/Controllers/Api/V1/Member/EventController.php` (card-start condition)
- Test: `tests/Feature/Experience/PrepaidRegistrationHoldTest.php`

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces: the register result's `status` may be `awaiting_payment`; result gains `payment_due_at`.

- [ ] **Step 1: Failing test** — `setUp()`, `member()`, `paidEvent()` and `register()` from Phase 1's `tests/Feature/Experience/EventRegistrationPayByIdTest.php`, then:

```php
    public function test_a_card_registration_is_held_and_a_ticket_switch_to_cash_releases_it(): void
    {
        $this->member();
        $event = $this->paidEvent(80);
        Http::fake(['*' => Http::response('', 500)]);

        $ticket = $this->register($event->id, ['payment_method' => 'paymera'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->json('data.ticket_id');

        $this->assertNotNull(EventAttendee::findOrFail($ticket)->payment_due_at);

        $this->register($event->id, ['ticket_id' => $ticket, 'payment_method' => 'cash'])->assertOk();

        $attendee = EventAttendee::findOrFail($ticket);
        $this->assertSame(EventRegistrationStatus::Approved, $attendee->status);
        $this->assertNull($attendee->payment_due_at);
    }

    public function test_a_registration_needing_approval_is_not_held(): void
    {
        $this->member();
        $event = $this->paidEvent(80, requiresApproval: true);

        $this->register($event->id, ['payment_method' => 'paymera'])
            ->assertJsonPath('data.status', 'pending');
    }
```

- [ ] **Step 2: Run** → FAIL (`approved`).

- [ ] **Step 3: The service** — inject `PaymentMethodRegistry $methods` and `PrepaymentHold $holds` into `EventRegistrationService`; after `$method = $paymentMethod ?? WalletMethod::KEY;`:
```php
            // A seat confirmed on the spot and paid by a prepaid method is
            // held until the card goes through or another method is chosen
            // (docs/decisions/prepayment-all-or-nothing.md).
            $held = $status === EventRegistrationStatus::Approved
                && $isPaidEvent && ! $madeFree
                && in_array($method, $this->methods->gatewayKeys(), true);

            if ($held) {
                $status = EventRegistrationStatus::AwaitingPayment;
            }
```
`EventAttendee::create([...])` gains `'payment_due_at' => $held ? $this->holds->dueAt($lockedEvent->start_at) : null,`. The two `if ($status === EventRegistrationStatus::Approved && ...)` blocks after it stay as they are (a hold is neither wallet- nor cash-paid). The returned array gains `'payment_due_at' => $attendee->payment_due_at?->toIso8601String(),`.

- [ ] **Step 4: The controller opens the card for a hold** — in `EventController::register()`:
```php
        if ($method !== null && in_array($method, $methods->gatewayKeys(), true)
            && in_array($attendee?->status, [EventRegistrationStatus::Approved, EventRegistrationStatus::AwaitingPayment], true)
            && $attendee->payment_state === EventPaymentState::Unpaid) {
```

- [ ] **Step 5: Run** — `PHP artisan test tests/Feature/Experience` → PASS (minus pre-existing).

- [ ] **Step 6: Commit**

```bash
PHP vendor/bin/pint --test app/Services/Experience/EventRegistrationService.php app/Http/Controllers/Api/V1/Member/EventController.php tests/Feature/Experience/PrepaidRegistrationHoldTest.php
git add app/Services/Experience/EventRegistrationService.php app/Http/Controllers/Api/V1/Member/EventController.php tests/Feature/Experience/PrepaidRegistrationHoldTest.php
git commit -m "feat(events): a seat paid by card is held for its payment, not approved

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Responses and the member's booking views

**Files:**
- Modify: `app/Http/Resources/MemberBookingDetailResource.php` and `app/Http/Resources/MemberBookingResource.php` — add `'payment_due_at' => $this->payment_due_at?->toIso8601String()` beside the status fields (read each file first; place it next to `status`/`status_label`)
- Test: add to `tests/Feature/Booking/PrepaidBookingHoldTest.php`

- [ ] **Step 1: Failing test**

```php
    public function test_the_member_sees_the_hold_its_deadline_and_why_it_was_cancelled(): void
    {
        $this->member();
        $space = $this->openSpace();
        Http::fake(['*' => Http::response('', 500)]);
        $id = $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_method' => 'paymera',
        ])->json('data.id');

        $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.payment_due_at', Carbon::parse('2026-08-17 08:15:00', 'Asia/Damascus')->utc()->toIso8601String());

        app(BookingCancellationService::class)->releaseUnpaidHold(Booking::findOrFail($id));

        $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$id}")
            ->assertJsonPath('data.status_note', __('api.booking.status_notes.payment_not_completed', [], 'en'));
    }
```
(Adjust `data.status` / `data.status_note` to the keys `MemberBookingDetailResource` actually uses for the status and its note — read the resource; keep the meaning.)

- [ ] **Step 2: Run** → FAIL. **Step 3:** add the field. **Step 4:** Run → PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Resources/MemberBookingDetailResource.php app/Http/Resources/MemberBookingResource.php tests/Feature/Booking/PrepaidBookingHoldTest.php
git commit -m "feat(bookings): the member sees a hold's deadline

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: `payments:cancel-expired-prepayments`

**Files:**
- Create: `app/Console/Commands/CancelExpiredPrepayments.php`
- Modify: `routes/console.php`
- Modify: `lang/en/api.php`, `lang/ar/api.php` (`event.prepayment_expired`)
- Test: `tests/Feature/Finance/CancelExpiredPrepaymentsTest.php`

**Interfaces:**
- Consumes: `BookingCancellationService::releaseUnpaidHold()` (Task 4), `SyncPaymentStatus::handle(Payment): Payment`, `Payment::scopeForPayable()`, `PaymentMethodRegistry::gatewayKeys()`.

- [ ] **Step 1: Failing test** — `setUp()`, `member()`, `fakeGateway()` from `tests/Feature/Finance/PayNowTest.php`, the `hold()` helper from Task 3's test, then:

```php
    public function test_an_expired_hold_is_cancelled_and_the_member_told(): void
    {
        $member = $this->member();
        $hold = $this->hold($member);
        Carbon::setTestNow(now()->addMinutes(16));

        $this->artisan('payments:cancel-expired-prepayments')->assertSuccessful();

        $hold->refresh();
        $this->assertSame(BookingStatus::Cancelled, $hold->status);
        $this->assertTrue($hold->cancelledAwaitingPayment());
        $this->assertSame(1, NotificationLog::where('user_id', $member->id)->where('template_key', 'booking.prepayment_expired')->count());
    }

    public function test_a_hold_whose_card_went_through_is_kept(): void
    {
        $member = $this->member();
        $hold = $this->hold($member);
        $this->fakeGateway('A');
        app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $hold, $member, 'paymera');
        Carbon::setTestNow(now()->addMinutes(16));

        $this->artisan('payments:cancel-expired-prepayments')->assertSuccessful();

        $this->assertSame(BookingStatus::Confirmed, $hold->refresh()->status);
        $this->assertSame(PaymentState::Paid, $hold->payment_state);
    }

    public function test_live_holds_and_ordinary_bookings_are_left_alone(): void
    {
        $member = $this->member();
        $live = $this->hold($member);
        $ordinary = Booking::factory()->create(['user_id' => $member->id, 'status' => BookingStatus::Confirmed, 'payment_state' => PaymentState::Unpaid]);

        $this->artisan('payments:cancel-expired-prepayments')->assertSuccessful();

        $this->assertSame(BookingStatus::AwaitingPayment, $live->refresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $ordinary->refresh()->status);
    }

    public function test_an_expired_registration_hold_is_rejected_with_its_reason(): void
    {
        $member = $this->member();
        $attendee = EventAttendee::factory()->create([
            'user_id' => $member->id,
            'status' => EventRegistrationStatus::AwaitingPayment,
            'payment_due_at' => now()->subMinute(),
            'price_points' => 80,
            'payment_state' => EventPaymentState::Unpaid,
        ]);

        $this->artisan('payments:cancel-expired-prepayments')->assertSuccessful();

        $attendee->refresh();
        $this->assertSame(EventRegistrationStatus::Rejected, $attendee->status);
        $this->assertSame(__('api.event.prepayment_expired'), $attendee->rejection_reason);
    }
```

- [ ] **Step 2: Run** → FAIL (command not found).

- [ ] **Step 3: The command**

```php
<?php

namespace App\Console\Commands;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Exceptions\PaymeraException;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Services\SyncPaymentStatus;
use App\Domain\Identity\Models\NotificationLog;
use App\Support\Enums\EventRegistrationStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A booking or registration made with a prepaid method is held for
 * payments.prepayment_window_minutes. One neither paid nor switched to
 * another method by its deadline is let go — after one last word from the
 * gateway, so a card the bank accepted in the meantime pays it instead
 * (docs/decisions/prepayment-all-or-nothing.md). Each hold is locked and
 * re-read where it is let go, so a payment landing in the same instant wins.
 */
class CancelExpiredPrepayments extends Command
{
    protected $signature = 'payments:cancel-expired-prepayments';

    protected $description = 'Cancel bookings and registrations whose prepayment was not completed in time.';

    public function handle(SyncPaymentStatus $sync, PaymentMethodRegistry $methods, BookingCancellationService $cancellations): int
    {
        $cancelled = 0;
        $rejected = 0;

        Booking::query()
            ->where('status', BookingStatus::AwaitingPayment)
            ->where('payment_due_at', '<=', now())
            ->chunkById(100, function ($holds) use ($sync, $methods, $cancellations, &$cancelled) {
                foreach ($holds as $hold) {
                    try {
                        $this->lastWordFromTheGateway($sync, $methods, $hold);

                        if ($cancellations->releaseUnpaidHold($hold)) {
                            $cancelled++;
                            $this->tell($hold->user_id, 'booking.prepayment_expired');
                        }
                    } catch (Throwable $e) {
                        Log::error('[Payments] could not let an expired booking hold go', ['booking_id' => $hold->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        EventAttendee::query()
            ->where('status', EventRegistrationStatus::AwaitingPayment)
            ->where('payment_due_at', '<=', now())
            ->chunkById(100, function ($holds) use ($sync, $methods, &$rejected) {
                foreach ($holds as $hold) {
                    try {
                        $this->lastWordFromTheGateway($sync, $methods, $hold);

                        if ($this->rejectRegistration($hold)) {
                            $rejected++;
                            $this->tell((int) $hold->user_id, 'event.registration_prepayment_expired');
                        }
                    } catch (Throwable $e) {
                        Log::error('[Payments] could not let an expired registration hold go', ['event_attendee_id' => $hold->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        if ($cancelled > 0 || $rejected > 0) {
            $this->info("Cancelled {$cancelled} booking hold(s); rejected {$rejected} registration hold(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Any card still pending for it is asked once more; an accepted one
     * pays it and releases the hold through the fulfiller. A gateway that
     * cannot be reached decides nothing: the hold is judged on what is known.
     */
    private function lastWordFromTheGateway(SyncPaymentStatus $sync, PaymentMethodRegistry $methods, Model $hold): void
    {
        Payment::query()
            ->forPayable($hold)
            ->where('status', PaymentStatus::Pending)
            ->whereIn('method', $methods->gatewayKeys())
            ->get()
            ->each(function (Payment $payment) use ($sync) {
                try {
                    $sync->handle($payment);
                } catch (PaymeraException) {
                    // Unreachable now; the reconciler keeps asking. A later
                    // acceptance credits the member's wallet (needs_review).
                }
            });
    }

    private function rejectRegistration(EventAttendee $hold): bool
    {
        return DB::transaction(function () use ($hold) {
            $locked = EventAttendee::query()->whereKey($hold->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== EventRegistrationStatus::AwaitingPayment) {
                return false;
            }

            $locked->forceFill([
                'status' => EventRegistrationStatus::Rejected,
                'rejection_reason' => __('api.event.prepayment_expired'),
                // No approved_by: nobody decided this, the deadline did.
                'approved_at' => now(),
            ])->save();

            return true;
        });
    }

    private function tell(int $userId, string $templateKey): void
    {
        NotificationLog::create([
            'user_id' => $userId,
            'channel' => 'push',
            'template_key' => $templateKey,
            'status' => 'sent',
        ]);
    }
}
```

`lang/en/api.php` `event`: `'prepayment_expired' => 'Your seat was released because the payment was not completed in time.',`
`lang/ar/api.php` `event`: `'prepayment_expired' => 'أُلغي مقعدك لأن الدفع لم يكتمل في الوقت المحدد.',`

`routes/console.php`, after the `payments:expire-pending` line:
```php
/*
 * A booking or registration made with a prepaid method is held for
 * payments.prepayment_window_minutes (15); one neither paid nor switched to
 * another method by then is let go. The window is minutes, so every minute.
 */
Schedule::command('payments:cancel-expired-prepayments')->everyMinute()->withoutOverlapping();
```

- [ ] **Step 4: Run** — `PHP artisan test tests/Feature/Finance/CancelExpiredPrepaymentsTest.php` → PASS.

- [ ] **Step 5: Full suite** — `PHP artisan test` → exactly the 17 pre-existing failures.

- [ ] **Step 6: Commit**

```bash
PHP vendor/bin/pint --test app/Console/Commands/CancelExpiredPrepayments.php routes/console.php tests/Feature/Finance/CancelExpiredPrepaymentsTest.php
git add app/Console/Commands/CancelExpiredPrepayments.php routes/console.php lang/en/api.php lang/ar/api.php tests/Feature/Finance/CancelExpiredPrepaymentsTest.php
git commit -m "feat(payments): let go of holds whose prepayment did not complete in time

Every minute, after one last word from the gateway: a booking hold is
cancelled (slot, discount and coupon given back), a registration hold
rejected with its reason, and the member told.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Documentation

**Files:**
- Modify: `docs/decisions/prepayment-all-or-nothing.md` — fill in the Phase 2 bullet: `awaiting_payment`, `payment_due_at` (set at the hold, cleared on release, kept on cancellation), the per-minute job, the check-in refusal, the approval notice on release; "What this changed in code" lists `PrepaymentHold`, `PendingBookingNotice`, `CancelExpiredPrepayments`, the migration
- Modify: `docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md` — "Data model": `payment_due_at` is **cleared when the hold is released** and kept when it is cancelled (replacing "never cleared afterwards"), with the reason: a hold paid and later cancelled must not read as unpaid
- Modify: `docs/paymera-mobile-api.md` §11 — the confirm screen: a card booking comes back `status: awaiting_payment` with `payment_due_at` (show a countdown); on failure send the same request with `booking_id` and another method before the deadline; after it, the booking is cancelled (`status_note` "payment not completed") and the member starts again; the same for registrations with `ticket_id`
- Modify: `postman/ADD-OS.postman_collection.json` — Create Booking's description and example: `awaiting_payment`, `payment_due_at`

- [ ] **Step 1: Write the docs above.**
- [ ] **Step 2: Validate Postman** — `PHP -r '$j = json_decode(file_get_contents("postman/ADD-OS.postman_collection.json"), true); echo $j === null ? "INVALID" : "valid";'` → `valid`.
- [ ] **Step 3: Commit**

```bash
git add docs postman
git commit -m "docs(payments): prepaid holds — awaiting_payment, the deadline, the job

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
