<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Settings\Services\SettingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class SessionClosureService
{
    public function __construct(
        private readonly BusinessHoursService $businessHours,
        private readonly SettingService $settings,
        private readonly AmountCalculator $amounts,
    ) {}

    /**
     * Reception enters a specific checkout time (decision #10). Computed
     * from the ACTUAL checked-in-to-checked-out duration, not the booking's
     * originally planned window — early departure or overrun is billed
     * correctly for both entity types uniformly.
     */
    public function closeOut(Booking|WalkinSession $session, CarbonInterface $enteredTime): void
    {
        $this->assertOpenForClosure($session);

        if ($enteredTime->lt($session->checked_in_at)) {
            throw new ReceptionActionException('api.reception.checkout_before_checkin');
        }

        $branch = $session->space->building->branch;
        $closingTime = $this->closingTimeFor($branch, $enteredTime);

        if ($closingTime === null || $this->localTimeOfDay($enteredTime) > $closingTime) {
            throw new ReceptionActionException('api.reception.checkout_past_closing');
        }

        $this->finalizeClosure($session, $enteredTime, TerminationSource::Reception);
    }

    /**
     * Identical effect to closeOut(), termination_source = auto, no
     * operator. Called by the scheduled command for any session still
     * checked in past its branch's closing time.
     */
    public function autoClose(Booking|WalkinSession $session, CarbonInterface $closingInstant): void
    {
        $this->finalizeClosure($session, $closingInstant, TerminationSource::Auto);
    }

    /**
     * Last period's close_time (H:i) for the branch's local calendar date
     * matching $instant, or null if closed that day. Public: Task 7's
     * auto-closure command needs this to decide which open sessions are
     * actually overdue.
     */
    public function closingTimeFor(Branch $branch, CarbonInterface $instant): ?string
    {
        $localInstant = $instant->copy()->setTimezone($this->settings->get('app.timezone', 'Asia/Damascus'));
        $periods = $this->businessHours->periodsFor($localInstant, $branch);

        if ($periods === []) {
            return null;
        }

        return Collection::make($periods)->pluck('close_time')->sort()->last();
    }

    private function assertOpenForClosure(Booking|WalkinSession $session): void
    {
        if ($session->checked_in_at === null) {
            throw new ReceptionActionException('api.reception.not_checked_in');
        }

        if ($session->checked_out_at !== null) {
            throw new ReceptionActionException('api.reception.already_checked_out', 409);
        }
    }

    private function finalizeClosure(Booking|WalkinSession $session, CarbonInterface $checkedOutAt, TerminationSource $source): void
    {
        [$amount, $currency] = $this->amounts->forRange($session->space, $session->checked_in_at, $checkedOutAt);

        $session->forceFill([
            'checked_out_at' => $checkedOutAt->copy()->setTimezone('UTC'),
            'termination_source' => $source,
            'amount_owed' => $amount,
            'currency' => $currency,
        ])->save();
    }

    private function localTimeOfDay(CarbonInterface $instant): string
    {
        return $instant->copy()->setTimezone($this->settings->get('app.timezone', 'Asia/Damascus'))->format('H:i');
    }
}
