<?php

namespace Add\Hr\Benefits\Services;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Benefits\Enums\BenefitGrantStatus;
use Add\Hr\Benefits\Enums\BenefitKind;
use Add\Hr\Benefits\Exceptions\BenefitFulfillmentFailed;
use Add\Hr\Benefits\Models\Benefit;
use Add\Hr\Benefits\Models\BenefitGrant;
use Add\Hr\Contracts\BenefitFulfiller;
use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;
use Add\Hr\Support\LocalDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Grants benefits, month by month. A month's grants go to everyone employed
 * on its first day and past the benefit's eligibility days by then, for
 * the benefits due that month (monthly, the first month of a quarter, the
 * grant month of a yearly one, or a one-time benefit not yet received).
 * One grant per benefit, employee and period, so running again only adds
 * what is missing.
 *
 * An entitlement is recorded as received; an in-kind grant is delivered by
 * the host's BenefitFulfiller, and a failure is kept for a retry; a cash
 * allowance waits for its payroll.
 */
class BenefitGranter
{
    public function __construct(
        private readonly BenefitResolver $resolver,
        private readonly BenefitFulfiller $fulfiller,
        private readonly HrNotifier $notifier,
    ) {}

    /**
     * `$month` may come in the organisation's timezone (the command, the
     * run endpoint); it is read as a calendar month, so its 1st compares
     * with hire dates as a day, not as an instant.
     *
     * @return array{granted: int, fulfilled: int, failed: int}
     */
    public function run(CarbonImmutable $month): array
    {
        $month = LocalDay::of($month)->startOfMonth();
        $grants = $this->grantMonth($month, $this->dueBenefits($month), $this->employedOn($month));

        foreach ($grants as $index => $grant) {
            if ($grant->benefit->kind === BenefitKind::InKindCredit) {
                $grants[$index] = $this->fulfil($grant);
            }
        }

        $count = fn (BenefitGrantStatus $status) => $grants->filter(fn (BenefitGrant $grant) => $grant->status === $status)->count();
        $failed = $count(BenefitGrantStatus::Failed);

        if ($failed > 0) {
            $this->notifier->notifyHolders(Abilities::BENEFITS_MANAGE, 'benefit_grants_failed', ['count' => $failed, 'month' => $month->format('Y-m')]);
        }

        return ['granted' => $grants->count(), 'fulfilled' => $count(BenefitGrantStatus::Fulfilled), 'failed' => $failed];
    }

    /**
     * One employee's grants for the month among `$benefits` (payroll makes
     * sure the month's cash allowances exist before paying them).
     *
     * @param  Collection<int, Benefit>  $benefits
     * @return Collection<int, BenefitGrant> the new grants
     */
    public function grantFor(Employee $employee, CarbonImmutable $month, Collection $benefits): Collection
    {
        $month = LocalDay::of($month)->startOfMonth();
        $due = $benefits->filter(fn (Benefit $benefit) => $benefit->is_active && $benefit->frequency->dueIn($month, $benefit->grant_month));

        return $employee->isEmployedOn($month) ? $this->grantMonth($month, $due, collect([$employee])) : collect();
    }

    /**
     * Delivers an in-kind grant that is pending or failed. A failure is
     * stored with its reason, never thrown.
     */
    public function fulfil(BenefitGrant $grant): BenefitGrant
    {
        try {
            return DB::transaction(function () use ($grant) {
                $grant = BenefitGrant::query()->with(['benefit', 'employee'])->lockForUpdate()->findOrFail($grant->id);

                if ($grant->benefit->kind !== BenefitKind::InKindCredit
                    || ! in_array($grant->status, [BenefitGrantStatus::Pending, BenefitGrantStatus::Failed], true)) {
                    return $grant;
                }

                $grant->update([
                    'status' => BenefitGrantStatus::Fulfilled,
                    'reference' => $this->fulfiller->fulfil($grant),
                    'failure' => null,
                    'attempts' => $grant->attempts + 1,
                    'fulfilled_at' => now(),
                ]);

                DB::afterCommit(fn () => $this->notifier->notifyEmployee($grant->employee, 'benefit_granted', [
                    'benefit_ar' => $grant->benefit->name['ar'] ?? '',
                    'benefit_en' => $grant->benefit->name['en'] ?? $grant->benefit->name['ar'] ?? '',
                    'amount' => $grant->amount,
                    'unit' => (string) $grant->unit,
                ]));

                return $grant;
            });
        } catch (BenefitFulfillmentFailed $e) {
            $grant = BenefitGrant::query()->with('benefit')->findOrFail($grant->id);
            $grant->update([
                'status' => BenefitGrantStatus::Failed,
                'failure' => mb_substr($e->getMessage(), 0, 255),
                'attempts' => $grant->attempts + 1,
            ]);

            return $grant;
        }
    }

    public function retry(BenefitGrant $grant): BenefitGrant
    {
        // A pending in-kind grant was left by a run that stopped before
        // delivering it; a pending cash allowance waits for payroll instead.
        $stuck = $grant->status === BenefitGrantStatus::Pending && $grant->benefit->kind === BenefitKind::InKindCredit;
        if ($grant->status !== BenefitGrantStatus::Failed && ! $stuck) {
            HrException::fail('hr::benefits.grant_not_failed');
        }

        return $this->fulfil($grant);
    }

    /**
     * A grant not delivered (pending or failed) is called off.
     */
    public function cancel(BenefitGrant $grant, int|string|null $actorId): BenefitGrant
    {
        return DB::transaction(function () use ($grant, $actorId) {
            $grant = BenefitGrant::query()->lockForUpdate()->findOrFail($grant->id);

            if (! in_array($grant->status, [BenefitGrantStatus::Pending, BenefitGrantStatus::Failed], true)) {
                HrException::fail('hr::benefits.grant_not_cancellable');
            }

            $grant->update(['status' => BenefitGrantStatus::Cancelled, 'cancelled_by' => $actorId]);

            return $grant;
        });
    }

    /**
     * @param  Collection<int, Benefit>  $benefits
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, BenefitGrant>
     */
    private function grantMonth(CarbonImmutable $month, Collection $benefits, Collection $employees): Collection
    {
        $created = collect();

        if ($benefits->isEmpty()) {
            return $created;
        }

        foreach ($employees as $employee) {
            foreach ($this->resolver->for($employee, $month, $benefits) as $assignment) {
                $benefit = $assignment->benefit;

                if ($employee->hired_on->addDays($benefit->eligible_after_days)->greaterThan($month)) {
                    continue;
                }

                $cost = $benefit->kind === BenefitKind::CashAllowance ? null : $this->resolver->employerCost($assignment);
                $entitlement = $benefit->kind === BenefitKind::Entitlement;
                $grant = BenefitGrant::query()->createOrFirst([
                    'benefit_id' => $benefit->id,
                    'employee_id' => $employee->id,
                    'period' => $benefit->frequency->period($month),
                ], [
                    'benefit_assignment_id' => $assignment->id,
                    'granted_on' => $month->toDateString(),
                    'amount' => $this->resolver->amount($assignment),
                    'currency' => $benefit->currency,
                    'unit' => $benefit->unit,
                    'employer_cost' => $cost,
                    'cost_currency' => $cost === null ? null : $benefit->cost_currency,
                    'status' => $entitlement ? BenefitGrantStatus::Fulfilled : BenefitGrantStatus::Pending,
                    'fulfilled_at' => $entitlement ? now() : null,
                ]);

                if ($grant->wasRecentlyCreated) {
                    $created->push($grant->setRelation('benefit', $benefit));
                }
            }
        }

        return $created;
    }

    /**
     * @return Collection<int, Benefit>
     */
    private function dueBenefits(CarbonImmutable $month): Collection
    {
        return Benefit::query()->where('is_active', true)->get()
            ->filter(fn (Benefit $benefit) => $benefit->frequency->dueIn($month, $benefit->grant_month))
            ->values();
    }

    /**
     * @return Collection<int, Employee>
     */
    private function employedOn(CarbonImmutable $day): Collection
    {
        return Employee::query()
            ->where('hired_on', '<=', $day->toDateString())
            ->where(fn ($q) => $q->whereNull('terminated_on')->orWhere('terminated_on', '>=', $day->toDateString()))
            ->orderBy('id')
            ->get();
    }
}
