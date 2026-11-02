<?php

namespace Add\Hr\Tests\Feature\Leave;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Core\Models\Position;
use Add\Hr\Leave\Models\LeaveType;
use Add\Hr\Tests\Concerns\BuildsLeave;
use Add\Hr\Tests\Concerns\HiresEmployees;
use Add\Hr\Tests\Concerns\TravelsOnTheOrganisationsClock;
use Add\Hr\Tests\TestCase;

class LeaveSettingsTest extends TestCase
{
    use BuildsLeave;
    use HiresEmployees;
    use TravelsOnTheOrganisationsClock;

    public function test_leave_types_are_read_with_view_and_written_with_settings(): void
    {
        $this->actingAsWith([Abilities::LEAVE_VIEW]);
        $this->postJson('/api/hr/leave-types', ['code' => 'annual', 'name' => ['ar' => 'سنوية']])->assertForbidden();

        $this->actingAsWith([Abilities::LEAVE_VIEW, Abilities::SETTINGS_MANAGE]);
        $id = $this->postJson('/api/hr/leave-types', ['code' => 'permission', 'name' => ['ar' => 'إذن'], 'unit' => 'hour'])
            ->assertCreated()
            ->assertJsonPath('data.unit', 'hour')
            ->assertJsonPath('data.tracks_balance', true)
            ->assertJsonPath('data.requires_attachment', false)
            ->json('data.id');

        $this->patchJson("/api/hr/leave-types/{$id}", ['unit' => 'day', 'hide_reason' => true])
            ->assertOk()->assertJsonPath('message', __('hr::leave.type_updated'));
        $this->getJson("/api/hr/leave-types/{$id}")->assertOk()
            ->assertJsonPath('data.unit', 'hour')
            ->assertJsonPath('data.hide_reason', true);
    }

    public function test_a_leave_type_in_use_cannot_be_deleted(): void
    {
        $this->actingAsWith([Abilities::SETTINGS_MANAGE]);
        $type = $this->leaveType();
        $this->policy($type);

        $this->deleteJson("/api/hr/leave-types/{$type->id}")->assertStatus(409);
        $this->deleteJson('/api/hr/leave-types/'.$this->leaveType()->id)->assertNoContent();
    }

    public function test_policies_validate_their_dates_and_tiers(): void
    {
        $this->actingAsWith([Abilities::SETTINGS_MANAGE, Abilities::LEAVE_VIEW]);
        $type = $this->leaveType();

        $this->postJson('/api/hr/leave-policies', [
            'leave_type_id' => $type->id,
            'effective_from' => '2026-01-01',
            'effective_to' => '2025-12-31',
            'accrual_method' => 'monthly',
            'service_tiers' => [['min_years' => 5, 'entitlement' => 25], ['min_years' => 5, 'entitlement' => 30]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['effective_to', 'service_tiers.0.min_years']);

        $id = $this->postJson('/api/hr/leave-policies', [
            'leave_type_id' => $type->id,
            'effective_from' => '2026-01-01',
            'accrual_method' => 'monthly',
            'annual_entitlement' => 21,
            'carry_over_max' => 5,
            'contract_types' => ['open_ended'],
        ])->assertCreated()
            ->assertJsonPath('data.annual_entitlement', '21')
            ->assertJsonPath('data.max_negative', '0')
            ->json('data.id');

        $this->patchJson("/api/hr/leave-policies/{$id}", ['effective_to' => '2026-12-31'])->assertOk();
        $this->getJson("/api/hr/leave-policies?leave_type_id={$type->id}")->assertOk()
            ->assertJsonPath('data.0.effective_to', '2026-12-31');
    }

    public function test_holidays_by_year_include_recurring_ones(): void
    {
        $this->actingAsWith([Abilities::SETTINGS_MANAGE, Abilities::LEAVE_VIEW]);

        $this->postJson('/api/hr/holidays', ['name' => ['ar' => 'رأس السنة'], 'starts_on' => '2020-01-01', 'ends_on' => '2020-01-01', 'recurs_annually' => true])->assertCreated();
        $this->postJson('/api/hr/holidays', ['name' => ['ar' => 'عيد الفطر'], 'starts_on' => '2026-03-20', 'ends_on' => '2026-03-22'])->assertCreated();
        $this->postJson('/api/hr/holidays', ['name' => ['ar' => 'عيد 2025'], 'starts_on' => '2025-03-30', 'ends_on' => '2025-04-01'])->assertCreated();
        $this->postJson('/api/hr/holidays', ['name' => ['ar' => 'طويلة'], 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_on');

        $this->getJson('/api/hr/holidays?year=2026')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_coverage_rule_covers_a_unit_or_a_position(): void
    {
        $this->actingAsWith([Abilities::SETTINGS_MANAGE]);
        $unit = OrgUnit::factory()->create();
        $position = Position::factory()->create();

        $this->postJson('/api/hr/coverage-rules', ['min_present' => 2])->assertUnprocessable();
        $this->postJson('/api/hr/coverage-rules', ['org_unit_id' => $unit->id, 'position_id' => $position->id, 'min_present' => 2])
            ->assertUnprocessable();
        $this->postJson('/api/hr/coverage-rules', ['position_id' => $position->id, 'min_present' => 1])
            ->assertCreated()->assertJsonPath('data.position_id', $position->id);
    }

    public function test_hr_adjusts_a_balance_and_reads_it(): void
    {
        $this->actingAsWith([Abilities::LEAVE_MANAGE, Abilities::LEAVE_VIEW]);
        $type = $this->leaveType();
        $this->policy($type);
        $employee = $this->hireEmployee();

        $this->postJson("/api/hr/employees/{$employee->id}/leave-adjustments", [
            'leave_type_id' => $type->id, 'kind' => 'encashment', 'amount' => 3, 'notes' => 'paid out',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->postJson("/api/hr/employees/{$employee->id}/leave-adjustments", [
            'leave_type_id' => $type->id, 'kind' => 'opening', 'amount' => 12.5, 'notes' => 'go-live balance',
        ])->assertCreated()->assertJsonPath('data.amount', '12.5');
        $this->postJson("/api/hr/employees/{$employee->id}/leave-adjustments", [
            'leave_type_id' => $type->id, 'kind' => 'encashment', 'amount' => -2.5, 'notes' => 'paid out',
        ])->assertCreated();

        $this->getJson("/api/hr/employees/{$employee->id}/leave-balances")->assertOk()
            ->assertJsonPath('data.0.balance', '10')
            ->assertJsonPath('data.0.leave_type.id', $type->id);
        $this->getJson("/api/hr/employees/{$employee->id}/leave-ledger")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_balance_routes_need_their_abilities(): void
    {
        $employee = $this->hireEmployee();
        $type = LeaveType::query()->create(['code' => 'x', 'name' => ['ar' => 'س']]);

        $this->actingAsWith([Abilities::LEAVE_VIEW]);
        $this->postJson("/api/hr/employees/{$employee->id}/leave-adjustments", [
            'leave_type_id' => $type->id, 'kind' => 'opening', 'amount' => 1, 'notes' => 'x',
        ])->assertForbidden();

        $this->actingAsWith([]);
        $this->getJson("/api/hr/employees/{$employee->id}/leave-balances")->assertForbidden();
    }

    public function test_an_adjustment_is_dated_on_the_organisations_day(): void
    {
        // 01:00 on 1 January locally, still 31 December in UTC.
        $this->travelToLocal('2027-01-01 01:00');
        $this->actingAsWith([Abilities::LEAVE_MANAGE, Abilities::LEAVE_VIEW]);
        $type = $this->leaveType();
        $this->policy($type);
        $employee = $this->hireEmployee();

        $this->postJson("/api/hr/employees/{$employee->id}/leave-adjustments", [
            'leave_type_id' => $type->id, 'kind' => 'opening', 'amount' => 5, 'notes' => 'go-live balance',
        ])->assertCreated()
            ->assertJsonPath('data.effective_on', '2027-01-01')
            ->assertJsonPath('data.period_year', 2027);
        $this->postJson("/api/hr/employees/{$employee->id}/leave-adjustments", [
            'leave_type_id' => $type->id, 'kind' => 'adjustment', 'amount' => 1, 'effective_on' => '2027-01-01', 'notes' => 'correction',
        ])->assertCreated();
    }
}
