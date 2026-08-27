<?php

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
 */
Schedule::command('model:prune')->hourly();

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
