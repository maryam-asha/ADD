<?php

namespace App\Domain\Leasing\Services;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Invoicing\Services\InvoiceIssuer;
use App\Domain\Leasing\Enums\LeasePeriodStatus;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Models\OfficeLeasePeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The once-a-day lease run (leases:run-daily). Every step looks for a state
 * that should not exist — a started period with no invoice, a lease with
 * access and no live grant, a live grant on a lease without access — so a
 * missed day or a failed lock call is simply caught up on the next run.
 * One lease or period failing never stops the rest.
 */
class LeaseDailyRunner
{
    private const LIVE = [AccessGrantStatus::Issued, AccessGrantStatus::Activated];

    public function __construct(
        private readonly InvoiceIssuer $invoices,
        private readonly OfficeLeaseService $leases,
    ) {}

    /** @return array{invoices: int, doors_opened: int, ended: int, doors_closed: int} */
    public function run(): array
    {
        return [
            'invoices' => $this->issueDueInvoices(),
            'doors_opened' => $this->openDoors(),
            'ended' => $this->endExpired(),
            'doors_closed' => $this->closeDoors(),
        ];
    }

    /** Started periods still upcoming: one invoice each, under the period's row lock. */
    public function issueDueInvoices(): int
    {
        $issued = 0;

        OfficeLeasePeriod::query()
            ->where('status', LeasePeriodStatus::Upcoming)
            ->whereDate('starts_on', '<=', $this->today())
            ->chunkById(100, function ($periods) use (&$issued) {
                foreach ($periods as $period) {
                    try {
                        $issued += DB::transaction(function () use ($period) {
                            $locked = OfficeLeasePeriod::query()->whereKey($period->id)->lockForUpdate()->first();

                            if ($locked?->status !== LeasePeriodStatus::Upcoming) {
                                return 0;
                            }

                            $this->invoices->issueForLeasePeriod($locked, null);

                            return 1;
                        });
                    } catch (\Throwable $e) {
                        Log::error('Failed to invoice an office lease period', [
                            'office_lease_period_id' => $period->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $issued;
    }

    /** Leases with access today and no live grant (openDoor() decides the rest). */
    public function openDoors(): int
    {
        $opened = 0;

        OfficeLease::query()
            ->where('status', '!=', OfficeLeaseStatus::Ended)
            ->whereDate('starts_on', '<=', $this->today())
            ->whereDoesntHave('accessGrants', fn ($q) => $q->whereIn('status', self::LIVE))
            ->chunkById(100, function ($leases) use (&$opened) {
                foreach ($leases as $lease) {
                    if ($this->leases->openDoor($lease)) {
                        $opened++;
                    }
                }
            });

        return $opened;
    }

    /** Active leases past their last day. */
    public function endExpired(): int
    {
        $ended = 0;

        OfficeLease::query()
            ->where('status', OfficeLeaseStatus::Active)
            ->whereDate('ends_on', '<', $this->today())
            ->chunkById(100, function ($leases) use (&$ended) {
                foreach ($leases as $lease) {
                    $lease->update(['status' => OfficeLeaseStatus::Ended]);
                    activity()->performedOn($lease)->log('lease_ended');
                    $ended++;
                }
            });

        return $ended;
    }

    /** Leases with a live grant that no longer grant access today. */
    public function closeDoors(): int
    {
        $closed = 0;
        $today = $this->today();

        OfficeLease::query()
            ->whereHas('accessGrants', fn ($q) => $q->whereIn('status', self::LIVE))
            ->chunkById(100, function ($leases) use (&$closed, $today) {
                foreach ($leases as $lease) {
                    if (! $lease->grantsAccessOn($today)) {
                        $this->leases->closeDoor($lease);
                        $closed++;
                    }
                }
            });

        return $closed;
    }

    private function today(): string
    {
        return now()->toDateString();
    }
}
