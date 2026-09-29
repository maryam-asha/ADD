<?php

namespace App\Domain\Booking\DataTransferObjects;

use App\Domain\Booking\Enums\CoworkingStartOutcome;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Membership\ValueObjects\SessionCoverage;

/** What a scan produced: a new session, the one already open, or a question for the member. */
final class CoworkingStartResult
{
    private function __construct(
        public readonly CoworkingStartOutcome $outcome,
        public readonly Booking|WalkinSession|null $session = null,
        public readonly ?SessionCoverage $coverage = null,
        public readonly ?IssuedScanTicket $ticket = null,
        public readonly ?int $hourlyRatePoints = null,
    ) {}

    public static function started(Booking|WalkinSession $session, ?SessionCoverage $coverage = null): self
    {
        return new self(CoworkingStartOutcome::Started, $session, $coverage);
    }

    public static function existing(Booking|WalkinSession $session): self
    {
        return new self(CoworkingStartOutcome::Existing, $session);
    }

    public static function confirmationRequired(SessionCoverage $coverage, IssuedScanTicket $ticket, int $hourlyRatePoints): self
    {
        return new self(CoworkingStartOutcome::ConfirmationRequired, null, $coverage, $ticket, $hourlyRatePoints);
    }
}
