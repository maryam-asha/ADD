<?php

namespace App\Console\Commands;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * System-driven counterpart to reception's manual checkout: any booking or
 * walk-in still checked in past its branch's closing time gets closed with
 * termination_source = auto, amount computed to closing time, exactly like
 * a manual checkout entered at that instant.
 */
class CloseOverdueReceptionSessions extends Command
{
    protected $signature = 'reception:close-overdue-sessions';

    protected $description = "Auto-close any booking/walk-in session still checked in past its branch's closing time.";

    public function handle(SessionClosureService $closures, SettingService $settings): int
    {
        $timezone = $settings->get('app.timezone', 'Asia/Damascus');
        $now = Carbon::now($timezone);

        WalkinSession::query()
            ->whereNotNull('checked_in_at')
            ->whereNull('checked_out_at')
            ->with('space.building.branch')
            ->chunkById(100, function ($sessions) use ($closures, $now) {
                foreach ($sessions as $session) {
                    $this->closeIfOverdue($closures, $session, $now);
                }
            });

        Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->whereNotNull('checked_in_at')
            ->whereNull('checked_out_at')
            ->with('space.building.branch')
            ->chunkById(100, function ($bookings) use ($closures, $now) {
                foreach ($bookings as $booking) {
                    $this->closeIfOverdue($closures, $booking, $now);
                }
            });

        return self::SUCCESS;
    }

    private function closeIfOverdue(SessionClosureService $closures, Booking|WalkinSession $session, Carbon $now): void
    {
        $branch = $session->space->building->branch;
        $closingTime = $closures->closingTimeFor($branch, $now);

        if ($closingTime === null || $now->format('H:i') <= $closingTime) {
            return;
        }

        $closingInstant = $now->copy()->setTimeFromTimeString($closingTime);

        // The member pressed "end" and reception never confirmed: bill to
        // that moment, not to closing time — but never past closing, since
        // autoClose() runs no closing check and a press at 20:03 that this
        // job only sees at 20:05 must not be billed for the extra minutes.
        $pending = $session->pendingEndRequest()->first();

        $closures->autoClose(
            $session,
            $pending !== null && $pending->requested_at->lessThan($closingInstant) ? $pending->requested_at : $closingInstant,
        );
    }
}
