<?php

namespace Add\Hr\Core\Http\Resources;

use Add\Hr\Core\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full employee file for HR. Never carries the national ID or the bank
 * account — only whether they are on file; EmployeeSensitiveResource, behind
 * its own ability and an audit entry, is the one place they are read.
 *
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assignment = $this->activeAssignment;
        $contract = $this->activeContract;

        return [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'gender' => $this->gender?->value,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'nationality' => $this->nationality,
            'marital_status' => $this->marital_status?->value,
            'phone' => $this->phone,
            'personal_email' => $this->personal_email,
            'work_email' => $this->work_email,
            'address' => $this->address,
            'has_national_id' => $this->national_id !== null,
            'has_bank_account' => $this->bank_account !== null,
            'status' => $this->status->value,
            'hired_on' => $this->hired_on->toDateString(),
            'probation_ends_on' => $this->probation_ends_on?->toDateString(),
            'confirmed_on' => $this->confirmed_on?->toDateString(),
            'terminated_on' => $this->terminated_on?->toDateString(),
            'termination_reason' => $this->termination_reason?->value,
            'current_assignment' => $assignment ? new AssignmentResource($assignment) : null,
            'current_contract' => $contract ? new ContractResource($contract) : null,
            'contacts' => $this->contacts->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'relation' => $c->relation,
                'phone' => $c->phone,
            ])->values(),
            'dependents' => $this->dependents->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'relation' => $d->relation,
                'date_of_birth' => $d->date_of_birth?->toDateString(),
            ])->values(),
        ];
    }
}
