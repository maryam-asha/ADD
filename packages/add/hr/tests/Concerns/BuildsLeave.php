<?php

namespace Add\Hr\Tests\Concerns;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Leave\Enums\LedgerKind;
use Add\Hr\Leave\Models\LeavePolicy;
use Add\Hr\Leave\Models\LeaveType;
use Add\Hr\Leave\Services\LeaveLedger;
use Carbon\CarbonImmutable;

/**
 * Leave types, policies and balances for leave tests.
 */
trait BuildsLeave
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function leaveType(array $attributes = []): LeaveType
    {
        return LeaveType::query()->create([
            'code' => 'type-'.uniqid(),
            'name' => ['ar' => 'سنوية', 'en' => 'Annual'],
            ...$attributes,
        ])->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function policy(LeaveType $type, array $attributes = []): LeavePolicy
    {
        return LeavePolicy::query()->create([
            'leave_type_id' => $type->id,
            'effective_from' => '2000-01-01',
            'accrual_method' => 'none',
            'annual_entitlement' => 21,
            ...$attributes,
        ])->refresh();
    }

    protected function giveBalance(Employee $employee, LeaveType $type, float $amount, ?string $on = null): void
    {
        $day = CarbonImmutable::parse($on ?? CarbonImmutable::today()->toDateString());

        app(LeaveLedger::class)->record($employee, $type, LedgerKind::Opening, $amount, $day, $day->year);
    }

    protected function balanceOf(Employee $employee, LeaveType $type): float
    {
        return app(LeaveLedger::class)->balance($employee, $type);
    }
}
