<?php

namespace App\Domain\Renewals\Services;

use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Services\MembershipActivationService;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Renewals\Exceptions\RenewalException;
use App\Domain\Renewals\Exceptions\RenewalRefused;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * The hourly renewal run (renewals:run). Within `renewals.advance_days` of
 * the end it renews subscriptions and extends leases that auto-renew,
 * storing why when it cannot; a stopped one is retried every run until it
 * ends. It also grants the balances of renewals that have started. One
 * failing subject never stops the rest.
 */
class RenewalRunner
{
    public const DEFAULT_ADVANCE_DAYS = 7;

    public function __construct(
        private readonly MembershipRenewalService $memberships,
        private readonly LeaseRenewalService $leases,
        private readonly MembershipActivationService $activations,
        private readonly SettingService $settings,
    ) {}

    /** @return array{memberships_renewed: int, memberships_stopped: int, leases_extended: int, leases_stopped: int, balances_granted: int} */
    public function run(): array
    {
        [$renewed, $stoppedMemberships] = $this->renewMemberships();
        [$extended, $stoppedLeases] = $this->extendLeases();

        return [
            'memberships_renewed' => $renewed,
            'memberships_stopped' => $stoppedMemberships,
            'leases_extended' => $extended,
            'leases_stopped' => $stoppedLeases,
            'balances_granted' => $this->grantStartedBalances(),
        ];
    }

    /** @return array{0: int, 1: int} */
    private function renewMemberships(): array
    {
        $renewed = $stopped = 0;

        Membership::query()
            ->live()
            ->where('auto_renew', true)
            ->where('owner_type', OwnerType::Company)
            ->where('current_period_end', '<=', now()->addDays($this->advanceDays()))
            ->whereDoesntHave('renewal')
            ->chunkById(100, function ($memberships) use (&$renewed, &$stopped) {
                foreach ($memberships as $membership) {
                    try {
                        $this->memberships->renew($membership, null, automatic: true);
                        $renewed++;
                    } catch (RenewalRefused $e) {
                        $this->stop($membership, $e->reason);
                        $stopped++;
                    } catch (InvoicingException) {
                        // The invoice currency has no exchange rate today.
                        $this->stop($membership, RenewalStopReason::NoExchangeRate);
                        $stopped++;
                    } catch (RenewalException) {
                        // Not renewable (not a provider assignment, auto-renew
                        // turned off meanwhile) or renewed meanwhile.
                    } catch (\Throwable $e) {
                        Log::error('Failed to renew a subscription', ['membership_id' => $membership->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return [$renewed, $stopped];
    }

    /** @return array{0: int, 1: int} */
    private function extendLeases(): array
    {
        $extended = $stopped = 0;
        $today = now()->toDateString();

        OfficeLease::query()
            ->where('status', OfficeLeaseStatus::Active)
            ->where('auto_renew', true)
            ->whereDate('ends_on', '>=', $today)
            ->whereDate('ends_on', '<=', now()->addDays($this->advanceDays())->toDateString())
            ->chunkById(100, function ($leases) use (&$extended, &$stopped) {
                foreach ($leases as $lease) {
                    try {
                        $this->leases->extend($lease, null, automatic: true);
                        $extended++;
                    } catch (RenewalRefused $e) {
                        $this->stop($lease, $e->reason);
                        $stopped++;
                    } catch (RenewalException) {
                        // Ended, terminated, extended or auto-renew turned off meanwhile.
                    } catch (\Throwable $e) {
                        Log::error('Failed to extend a lease', ['office_lease_id' => $lease->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return [$extended, $stopped];
    }

    private function grantStartedBalances(): int
    {
        $granted = 0;

        Membership::query()
            ->whereNull('balances_granted_at')
            ->where('current_period_start', '<=', now())
            ->chunkById(100, function ($memberships) use (&$granted) {
                foreach ($memberships as $membership) {
                    try {
                        if ($this->activations->grantDueBalances($membership)) {
                            $granted++;
                        }
                    } catch (\Throwable $e) {
                        Log::error('Failed to grant a renewal its plan balances', ['membership_id' => $membership->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return $granted;
    }

    /** Stores the reason (logged only when it changes, not every hour). */
    private function stop(Model $subject, RenewalStopReason $reason): void
    {
        if ($subject->renewal_stop_reason === $reason) {
            return;
        }

        $subject->update(['renewal_stop_reason' => $reason]);
        activity()->performedOn($subject)->withProperties(['reason' => $reason->value])->log('renewal_stopped');
    }

    private function advanceDays(): int
    {
        return (int) $this->settings->get('renewals.advance_days', self::DEFAULT_ADVANCE_DAYS);
    }
}
