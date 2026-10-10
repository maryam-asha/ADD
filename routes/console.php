<?php

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormSubmission;
use App\Domain\Identity\Models\PendingRegistration;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Abandoned sign-ups are the normal case, not the exception: someone mistypes a
 * number, never receives a code, and walks away. Without this sweep,
 * `pending_registrations` would accumulate unverified names and email addresses
 * indefinitely — see App\Domain\Identity\Models\PendingRegistration::prunable().
 *
 * The form engine's rows go the same way when a database cascade removes
 * their owner or the record they answered (Form/FormSubmission::prunable()),
 * which fires no model event.
 *
 * The models are named, not discovered: discovery only looks in app/Models,
 * which does not exist here, so a bare `model:prune` fails every run.
 * PrunableModelsAreScheduledTest fails if a Prunable model is left off.
 */
Schedule::command('model:prune', ['--model' => [
    PendingRegistration::class,
    Form::class,
    FormSubmission::class,
]])->hourly();

/*
 * Reception operations (docs/superpowers/specs/2026-08-16-reception-operations-design.md)
 * — a booking or walk-in session still checked in past its branch's
 * closing time must be closed automatically (termination_source = auto),
 * not left open indefinitely until reception happens to notice.
 */
Schedule::command('reception:close-overdue-sessions')->everyFiveMinutes()->withoutOverlapping();

/*
 * Reception kiosk (docs/decisions/kiosk-display.md) — a pending arrival
 * request older than its configured window is stale, not actionable; sweep
 * it to `expired` so reception's queue doesn't accumulate entries from
 * members who scanned and then left.
 */
Schedule::command('kiosk:expire-stale-arrival-requests')->everyFiveMinutes()->withoutOverlapping();

/*
 * External exchange-rate suggestion (docs/decisions/exchange-rate-external-suggestion.md)
 * — sp-today's nationwide USD/SYP quote, fetched once a day as a candidate
 * for an admin to review and accept; never written to exchange_rates
 * automatically.
 */
Schedule::command('finance:fetch-exchange-rate-suggestion')->dailyAt('09:00')->timezone('Asia/Damascus')->withoutOverlapping();
Schedule::command('finance:sync-exchange-rates')->everySixHours()->withoutOverlapping();

/*
 * Access control (docs/decisions/qr-lock-unlock.md) — the lock passcode
 * lifecycle: issue once a booking's cancellation window closes, revoke on
 * a maintenance conflict, expire anything never activated in time.
 */
Schedule::command('access:issue-grants-on-cancellation-window-close')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('access:revoke-grants-on-maintenance')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('access:expire-unactivated-grants')->everyFiveMinutes()->withoutOverlapping();
// Final whole-branch review C1 — BookingCancellationService::cancel() never
// touches the Access domain; this polls Booking's already-public status
// instead, so a same-day booking's grant doesn't survive its own
// cancellation.
Schedule::command('access:revoke-grants-on-booking-cancellation')->everyFiveMinutes()->withoutOverlapping();

/*
 * Events (docs/decisions/event-approval-and-custom-forms.md) — a pending
 * registration holds a seat but is free, since points are only taken on
 * approval. Anything still undecided by its deadline is closed out so the
 * seats reopen while there is still time to fill them.
 */
Schedule::command('events:expire-pending-registrations')->hourly()->withoutOverlapping();

/*
 * Bookings (docs/decisions/booking-outcomes-and-extension.md) — a request
 * still undecided when it starts lapses to rejected with its points refunded,
 * and a confirmed booking nobody checked into is a no-show once it ends.
 */
Schedule::command('bookings:close-elapsed')->everyFiveMinutes()->withoutOverlapping();

/*
 * Card payments (docs/paymera.md) — the backstop under the callback and the
 * trigger. A payment can be left pending by a closed browser, a lost trigger
 * request or a queue worker that was down; without this sweep it would stay
 * pending forever while the member's money sits at the bank. Every check
 * goes through SyncPaymentStatus, which locks and re-checks finality, so an
 * overlap with a live callback cannot double-credit.
 */
Schedule::command('paymera:reconcile')->everyFiveMinutes()->withoutOverlapping();

/*
 * Plan holds opened for cash before plans stopped taking cash lapse when
 * nobody came to pay them. Cash holds only — gateway payments are
 * paymera:reconcile's.
 */
Schedule::command('payments:expire-pending')->hourly()->withoutOverlapping();

/*
 * A booking or registration made with a prepaid method is held for
 * payments.prepayment_window_minutes (15); one neither paid nor switched to
 * another method by then is let go. The window is minutes, so every minute.
 */
Schedule::command('payments:cancel-expired-prepayments')->everyMinute()->withoutOverlapping();

/*
 * Office leases (docs/superpowers/specs/2026-10-10-office-leases-design.md):
 * invoice each billing period on its first day, open the office lock on the
 * lease's first day, end leases past their last day and close their lock.
 * Every step catches up on what a missed run left behind. Hourly, despite the
 * name: a lock call that failed, or a door a lease reopens (maintenance over,
 * a lease made after a run), is retried within the hour rather than the next
 * morning. Each step is idempotent: a period is invoiced once, a door opened
 * only while it has no live grant.
 */
Schedule::command('leases:run-daily')->hourly()->withoutOverlapping();
