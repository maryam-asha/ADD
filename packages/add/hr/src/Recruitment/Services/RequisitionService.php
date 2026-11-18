<?php

namespace Add\Hr\Recruitment\Services;

use Add\Hr\Approvals\Services\ApprovalEngine;
use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Recruitment\Enums\OpeningStatus;
use Add\Hr\Recruitment\Enums\RequisitionStatus;
use Add\Hr\Recruitment\Models\Requisition;
use Add\Hr\Support\HrException;
use Illuminate\Support\Facades\DB;

/**
 * Requisitions: filed with the hiring manager as the approval requester,
 * then filled by hires or closed by hand. Ending one closes its openings.
 */
class RequisitionService
{
    public const APPROVAL_TYPE = 'requisition';

    public function __construct(private readonly ApprovalEngine $approvals) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function file(array $data, Employee $hiringManager, int|string|null $actorId): Requisition
    {
        return DB::transaction(function () use ($data, $hiringManager, $actorId) {
            $requisition = Requisition::query()->create([
                'org_unit_id' => $data['org_unit_id'],
                'position_id' => $data['position_id'] ?? null,
                'job_grade_id' => $data['job_grade_id'] ?? null,
                'title' => $data['title'],
                'headcount' => $data['headcount'] ?? 1,
                'employment_type' => $data['employment_type'],
                'reason' => $data['reason'],
                'replaces_employee_id' => $data['replaces_employee_id'] ?? null,
                'hiring_manager_id' => $hiringManager->id,
                'budget_min' => $data['budget_min'] ?? null,
                'budget_max' => $data['budget_max'] ?? null,
                'budget_currency' => isset($data['budget_currency']) ? strtoupper($data['budget_currency']) : null,
                'justification' => $data['justification'] ?? null,
                'status' => RequisitionStatus::Pending,
                'requested_by' => $actorId,
            ]);

            $requisition->approval_request_id = $this->approvals->submit($requisition, self::APPROVAL_TYPE, $hiringManager, $actorId)->id;
            $requisition->save();

            HrAudit::record('requisition_filed', $requisition);

            return $requisition;
        });
    }

    public function cancel(Requisition $requisition): Requisition
    {
        return DB::transaction(function () use ($requisition) {
            $requisition = Requisition::query()->lockForUpdate()->findOrFail($requisition->id);

            if ($requisition->status === RequisitionStatus::Pending) {
                $this->approvals->cancel($requisition->approvalRequest);
            } elseif ($requisition->status !== RequisitionStatus::Approved || $requisition->hiredCount() > 0) {
                HrException::fail('hr::recruitment.requisition_not_cancellable');
            }

            return $this->end($requisition, RequisitionStatus::Cancelled);
        });
    }

    /**
     * Stops hiring for an approved requisition, whoever was hired so far.
     */
    public function close(Requisition $requisition): Requisition
    {
        return DB::transaction(function () use ($requisition) {
            $requisition = Requisition::query()->lockForUpdate()->findOrFail($requisition->id);

            if ($requisition->status !== RequisitionStatus::Approved) {
                HrException::fail('hr::recruitment.requisition_not_open');
            }

            return $this->end($requisition, RequisitionStatus::Closed);
        });
    }

    /**
     * Filled once its hires reach the headcount. Called inside the hire's
     * transaction.
     */
    public function fillIfComplete(Requisition $requisition): void
    {
        if ($requisition->status === RequisitionStatus::Approved && $requisition->hiredCount() >= $requisition->headcount) {
            $this->end($requisition, RequisitionStatus::Filled);
        }
    }

    private function end(Requisition $requisition, RequisitionStatus $status): Requisition
    {
        $requisition->update(['status' => $status, 'filled_at' => $status === RequisitionStatus::Filled ? now() : null]);
        $requisition->openings()->where('status', '!=', OpeningStatus::Closed->value)
            ->update(['status' => OpeningStatus::Closed->value, 'closed_at' => now()]);

        HrAudit::record('requisition_'.$status->value, $requisition);

        return $requisition;
    }
}
