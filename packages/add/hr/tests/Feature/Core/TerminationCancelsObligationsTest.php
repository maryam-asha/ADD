<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Authorization\Abilities;
use Add\Hr\Payroll\Models\Loan;
use Add\Hr\Tests\Concerns\BuildsPayroll;
use Add\Hr\Tests\Concerns\HiresStaff;
use Add\Hr\Tests\TestCase;
use Add\Hr\Training\Models\LearningRequest;
use Add\Hr\Training\Services\LearningBudget;

/**
 * Recording a termination calls off what would bind the organisation
 * after the employee leaves: a pending loan and a pending learning spend.
 * Requests for work already done (entries, overtime, timesheets) stay.
 */
class TerminationCancelsObligationsTest extends TestCase
{
    use BuildsPayroll;
    use HiresStaff;

    public function test_pending_loans_and_learning_requests_are_cancelled_with_their_approvals(): void
    {
        $this->travelTo('2026-04-05 09:00:00');
        $this->payStructure();
        $manager = $this->staff();
        $employee = $this->reportOf($manager);
        $this->pay($employee, ['basic' => 1000]);
        app(LearningBudget::class)->allocate(2026, '300', [$employee->id], null);

        $this->actingAsEmployee($employee);
        $this->postJson('/api/hr/me/loans', ['amount' => 500, 'currency' => 'USD', 'installments' => 5])->assertCreated();
        $this->postJson('/api/hr/me/learning-requests', ['title' => 'Latte art', 'amount' => 100])->assertCreated();

        $this->actingAs($this->user([Abilities::LIFECYCLE_MANAGE]));
        $this->postJson("/api/hr/employees/{$employee->id}/events", ['type' => 'termination', 'effective_on' => '2026-04-30', 'reason' => 'resignation'])
            ->assertCreated();

        $this->assertSame('cancelled', Loan::query()->sole()->status->value);
        $this->assertSame('cancelled', LearningRequest::query()->sole()->status->value);
        $this->assertSame(['cancelled', 'cancelled'], ApprovalRequest::query()->pluck('status')->map->value->all());
    }
}
