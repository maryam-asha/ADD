<?php

namespace App\Domain\Booking\DataTransferObjects;

use App\Domain\Booking\Enums\CoworkingStartOutcome;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Membership\ValueObjects\SessionCoverage;

/** What a scan produced: a new session, a question for the member, or a word to reception. */
final class CoworkingStartResult
{
    private function __construct(
        public readonly CoworkingStartOutcome $outcome,
        public readonly Booking|WalkinSession|null $session = null,
        public readonly ?SessionCoverage $coverage = null,
        public readonly ?IssuedScanTicket $ticket = null,
        public readonly ?int $hourlyRatePoints = null,
        public readonly ?ArrivalRequest $arrivalRequest = null,
        public readonly ?Booking $booking = null,
    ) {}

    public static function started(Booking|WalkinSession $session, ?SessionCoverage $coverage = null): self
    {
        return new self(CoworkingStartOutcome::Started, $session, $coverage);
    }

    public static function confirmationRequired(SessionCoverage $coverage, IssuedScanTicket $ticket, int $hourlyRatePoints): self
    {
        return new self(CoworkingStartOutcome::ConfirmationRequired, null, $coverage, $ticket, $hourlyRatePoints);
    }

    public static function arrivalRequested(ArrivalRequest $arrivalRequest, Booking $booking): self
    {
        return new self(CoworkingStartOutcome::ArrivalRequested, arrivalRequest: $arrivalRequest, booking: $booking);
    }
}
