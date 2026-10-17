<?php

namespace Add\Hr\Training\Services;

use Add\Hr\Approvals\Services\ApprovalEngine;
use Add\Hr\Audit\HrAudit;
use Add\Hr\Contracts\HrClock;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Decimal;
use Add\Hr\Support\HrException;
use Add\Hr\Training\Enums\LearningRequestStatus;
use Add\Hr\Training\Models\LearningRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Requests to spend the learning budget on outside learning, through
 * approvals (type `learning`). The amount must be available when asked
 * and again at the final approval, which records the spending.
 */
class LearningRequestService
{
    public const APPROVAL_TYPE = 'learning';

    public function __construct(
        private readonly ApprovalEngine $approvals,
        private readonly LearningBudget $budget,
        private readonly HrClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(Employee $employee, array $data, int|string|null $actorId): LearningRequest
    {
        return DB::transaction(function () use ($employee, $data, $actorId) {
            // One employee's requests are checked against their balance one at a time.
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            $year = isset($data['starts_on'])
                ? CarbonImmutable::parse($data['starts_on'])->year
                : CarbonImmutable::now($this->clock->timezone())->year;
            $amount = Decimal::of($data['amount']);
            $this->budget->assertAvailable($employee->id, $year, $amount);

            $request = LearningRequest::query()->create([
                'employee_id' => $employee->id,
                'course_id' => $data['course_id'] ?? null,
                'title' => $data['title'],
                'provider' => $data['provider'] ?? null,
                'starts_on' => $data['starts_on'] ?? null,
                'year' => $year,
                'amount' => $amount,
                'currency' => $this->budget->currency(),
                'justification' => $data['justification'] ?? null,
                'status' => LearningRequestStatus::Pending,
                'requested_by' => $actorId,
            ]);
            $request->approval_request_id = $this->approvals->submit($request, self::APPROVAL_TYPE, $employee, $actorId)->id;
            $request->save();
            HrAudit::record('learning_requested', $request);

            return $request;
        });
    }

    public function cancel(LearningRequest $request, Employee $by): LearningRequest
    {
        return DB::transaction(function () use ($request, $by) {
            $request = LearningRequest::query()->with('approvalRequest')->lockForUpdate()->findOrFail($request->id);
            if ($request->employee_id !== $by->id) {
                HrException::fail('hr::training.not_your_request', [], 403);
            }
            if ($request->status !== LearningRequestStatus::Pending) {
                HrException::fail('hr::approvals.not_pending');
            }

            $this->approvals->cancel($request->approvalRequest);
            $request->update(['status' => LearningRequestStatus::Cancelled]);
            HrAudit::record('learning_request_cancelled', $request);

            return $request;
        });
    }
}
