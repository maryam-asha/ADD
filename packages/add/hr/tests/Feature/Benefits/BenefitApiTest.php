<?php

namespace Add\Hr\Tests\Feature\Benefits;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Benefits\Models\BenefitGrant;
use Add\Hr\Contracts\BenefitFulfiller;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\JobGrade;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Tests\Concerns\HiresStaff;
use Add\Hr\Tests\Fixtures\RecordingBenefitFulfiller;
use Add\Hr\Tests\Fixtures\User;
use Add\Hr\Tests\TestCase;

/**
 * Benefits over HTTP: defining them by kind, assigning them without
 * overlaps, granting, retrying and cancelling, what the employee sees, and
 * the full cost report.
 */
class BenefitApiTest extends TestCase
{
    use HiresStaff;

    private RecordingBenefitFulfiller $fulfiller;

    private User $hr;

    private Employee $employee;

    private JobGrade $grade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-04-05 09:00:00');
        $this->fulfiller = new RecordingBenefitFulfiller;
        $this->app->instance(BenefitFulfiller::class, $this->fulfiller);
        $this->grade = JobGrade::factory()->create();
        $this->employee = $this->staff([], ['hired_on' => '2025-01-01', 'assignment' => ['job_grade_id' => $this->grade->id]]);
        $this->hr = $this->user([Abilities::BENEFITS_VIEW, Abilities::BENEFITS_MANAGE, Abilities::PAYROLL_VIEW]);
    }

    private function cafeCredit(): int
    {
        return $this->postJson('/api/hr/benefits', [
            'code' => 'cafe_credit',
            'name' => ['ar' => 'رصيد مقهى', 'en' => 'Cafe credit'],
            'kind' => 'in_kind_credit',
            'frequency' => 'monthly',
            'amount' => 100,
            'unit' => 'points',
            'employer_cost' => 12.5,
            'cost_currency' => 'USD',
            'fulfillment' => ['pocket' => 'cafe'],
        ])->assertCreated()->assertJsonPath('data.fulfillment.pocket', 'cafe')->json('data.id');
    }

    public function test_each_kind_carries_its_own_fields(): void
    {
        $this->actingAs($this->hr);
        $this->cafeCredit();

        $this->postJson('/api/hr/benefits', [
            'code' => 'bad_credit', 'name' => ['ar' => 'x'], 'kind' => 'in_kind_credit', 'frequency' => 'monthly',
            'amount' => 1, 'currency' => 'USD', 'fulfillment' => ['pocket' => 'casino'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['unit', 'currency', 'fulfillment.pocket']);

        $this->postJson('/api/hr/benefits', [
            'code' => 'phone', 'name' => ['ar' => 'بدل هاتف'], 'kind' => 'cash_allowance', 'frequency' => 'yearly', 'grant_month' => 13,
            'amount' => 20, 'currency' => 'USD', 'employer_cost' => 20,
        ])->assertUnprocessable()->assertJsonValidationErrors(['pay_component_id', 'employer_cost', 'grant_month']);

        // A cash allowance needs an earning paid by benefit_allowance that applies to everyone.
        $fixed = PayComponent::query()->create(['code' => 'transport', 'name' => ['ar' => 'مواصلات'], 'kind' => 'earning', 'calculator' => 'fixed']);
        $this->postJson('/api/hr/benefits', [
            'code' => 'phone', 'name' => ['ar' => 'بدل هاتف'], 'kind' => 'cash_allowance', 'frequency' => 'monthly',
            'amount' => 20, 'currency' => 'USD', 'pay_component_id' => $fixed->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['pay_component_id']);
    }

    public function test_assign_grant_retry_cancel_and_the_employee_view(): void
    {
        $this->actingAs($this->hr);
        $id = $this->cafeCredit();
        $assignment = $this->postJson("/api/hr/benefits/{$id}/assignments", ['job_grade_id' => $this->grade->id, 'effective_from' => '2026-01-01'])
            ->assertCreated()->assertJsonPath('data.target', 'grade')->json('data.id');
        $this->postJson("/api/hr/benefits/{$id}/assignments", ['job_grade_id' => $this->grade->id, 'effective_from' => '2026-06-01'])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::benefits.assignment_overlap'));
        $this->postJson("/api/hr/benefits/{$id}/assignments", ['job_grade_id' => $this->grade->id, 'org_unit_id' => 1, 'effective_from' => '2026-06-01'])
            ->assertUnprocessable();
        $this->postJson("/api/hr/benefits/{$id}/assignments", ['job_grade_id' => $this->grade->id, 'excluded' => true, 'effective_from' => '2027-06-01'])
            ->assertUnprocessable()->assertJsonValidationErrors(['excluded']);

        $this->fulfiller->failWith = 'No wallet';
        $this->postJson('/api/hr/benefit-grants/run', ['month' => '2026-04'])->assertOk()
            ->assertJsonPath('data.granted', 1)->assertJsonPath('data.failed', 1);
        $grant = $this->getJson('/api/hr/benefit-grants?status=failed')->assertOk()->assertJsonPath('data.0.failure', 'No wallet')->json('data.0.id');

        $this->postJson("/api/hr/benefit-grants/{$grant}/retry")->assertOk()->assertJsonPath('data.status', 'failed');
        $this->fulfiller->failWith = null;
        $this->postJson("/api/hr/benefit-grants/{$grant}/retry")->assertOk()->assertJsonPath('data.status', 'fulfilled');
        $this->postJson("/api/hr/benefit-grants/{$grant}/cancel")->assertUnprocessable();
        $this->postJson("/api/hr/benefit-grants/{$grant}/retry")->assertUnprocessable();

        // Used: neither the benefit nor the assignment can be deleted, only ended.
        $this->deleteJson("/api/hr/benefits/{$id}")->assertStatus(409);
        $this->deleteJson("/api/hr/benefit-assignments/{$assignment}")->assertStatus(409);
        $this->patchJson("/api/hr/benefit-assignments/{$assignment}", ['effective_to' => '2025-12-31'])->assertUnprocessable();
        $this->patchJson("/api/hr/benefit-assignments/{$assignment}", ['effective_to' => '2026-04-30'])->assertOk();

        $this->getJson("/api/hr/employees/{$this->employee->id}/benefits")->assertOk()
            ->assertJsonPath('data.benefits.0.target', 'grade')
            ->assertJsonPath('data.grants.0.reference', 'tx-'.$grant);
        $this->getJson('/api/hr/reports/employee-cost?month=2026-04')->assertOk()
            ->assertJsonPath('data.employees.0.costs.0.benefits', '12.5')
            ->assertJsonPath('data.totals.USD.total', '12.5');

        $this->actingAsEmployee($this->employee);
        $this->getJson('/api/hr/me/benefits')->assertOk()
            ->assertJsonPath('data.benefits.0.code', 'cafe_credit')
            ->assertJsonPath('data.grants.0.status', 'fulfilled')
            ->assertJsonMissingPath('data.grants.0.reference');
    }

    public function test_a_pending_grant_can_be_cancelled(): void
    {
        $this->actingAs($this->hr);
        $id = $this->postJson('/api/hr/benefits', [
            'code' => 'meal', 'name' => ['ar' => 'وجبة'], 'kind' => 'in_kind_credit', 'frequency' => 'monthly', 'amount' => 30, 'unit' => 'meals',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/hr/benefits/{$id}/assignments", ['employee_id' => $this->employee->id, 'effective_from' => '2026-04-01'])->assertCreated();
        $this->fulfiller->failWith = 'Down';
        $this->postJson('/api/hr/benefit-grants/run')->assertOk()->assertJsonPath('data.month', '2026-04');

        $grant = BenefitGrant::query()->sole();
        $this->postJson("/api/hr/benefit-grants/{$grant->id}/cancel")->assertOk();
        $this->assertSame('cancelled', $grant->fresh()->status->value);
    }

    public function test_reading_and_managing_are_separate_abilities(): void
    {
        $this->actingAs($this->user([Abilities::BENEFITS_VIEW]));
        $this->getJson('/api/hr/benefits')->assertOk();
        $this->getJson('/api/hr/reports/employee-cost?month=2026-04')->assertForbidden();
        $this->postJson('/api/hr/benefits', [])->assertForbidden();
        $this->postJson('/api/hr/benefit-grants/run')->assertForbidden();

        $this->actingAs($this->user());
        $this->getJson('/api/hr/benefits')->assertForbidden();
    }
}
