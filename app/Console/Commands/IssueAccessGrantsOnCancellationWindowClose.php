<?php

namespace App\Console\Commands;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Enums\AccessRevokeReason;
use App\Domain\Access\Services\AccessNotifier;
use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * A confirmed booking's cancellation window closing (or a same-day
 * booking, immediately) is when its space's lock gets a Period passcode
 * — docs/decisions/qr-lock-unlock.md §2. Skips any booking that already
 * has a live or expired grant (source_type=booking, source_id=this booking)
 * so a re-run before the next window doesn't double-issue.
 */
class IssueAccessGrantsOnCancellationWindowClose extends Command
{
    protected $signature = 'access:issue-grants-on-cancellation-window-close';

    protected $description = "Issue an access grant for each confirmed booking whose cancellation window has closed and doesn't have one yet.";

    public function handle(PasscodeIssuanceService $issuance, SettingService $settings, AccessNotifier $notices): int
    {
        Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->where('end_at', '>', now())
            // Only a door that exists and is open for use: a space with no
            // lock (co-working, most rooms) has nothing to issue, and one in
            // maintenance would only have the code revoked again.
            ->whereHas('space', fn ($space) => $space
                ->where('status', OperationalStatus::Active)
                ->whereHas('devices', fn ($device) => $device->where('type', 'lock')))
            // A live code, one left unused until it expired, or one staff
            // stopped (both re-issued by the desk, never here) ends it; any
            // other revoked one does not — a booking whose door maintenance
            // closed gets a new code when the space is active again.
            ->whereDoesntHave('accessGrants', fn ($grant) => $grant->where(fn ($ending) => $ending
                ->whereIn('status', [AccessGrantStatus::Issued, AccessGrantStatus::Activated, AccessGrantStatus::Expired])
                ->orWhere('revoke_reason', AccessRevokeReason::Staff)))
            ->with('space')
            ->chunkById(100, function ($bookings) use ($issuance, $settings, $notices) {
                foreach ($bookings as $booking) {
                    $windowMinutes = $booking->space->cancellation_window_minutes
                        ?? $settings->get('booking.cancellation_window_minutes', 60);
                    $windowClosed = now()->gt($booking->start_at->copy()->subMinutes($windowMinutes));
                    $sameDay = now()->isSameDay($booking->start_at);

                    if (! $windowClosed && ! $sameDay) {
                        continue;
                    }

                    try {
                        $issuance->issueForBooking($booking);
                        $notices->passcodeReady($booking);
                    } catch (\Throwable $e) {
                        Log::error('Failed to issue access grant for booking', [
                            'booking_id' => $booking->id, 'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return self::SUCCESS;
    }
}
