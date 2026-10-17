<?php

namespace Add\Hr\Core\Http\Resources;

use Add\Hr\Core\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The employee's own file as they see it: no sensitive values, no HR-only
 * fields, plus a summary of what their document file is missing.
 *
 * @mixin \Add\Hr\Core\Models\Employee
 */
class MyProfileResource extends JsonResource
{
    /**
     * @param  array{missing: list<DocumentType>, expired: list<mixed>, complete: bool}  $compliance
     */
    public function __construct($resource, private readonly array $compliance)
    {
        parent::__construct($resource);
    }

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
            'name' => $this->name,
            'gender' => $this->gender?->value,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'phone' => $this->phone,
            'personal_email' => $this->personal_email,
            'work_email' => $this->work_email,
            'address' => $this->address,
            'status' => $this->status->value,
            'hired_on' => $this->hired_on->toDateString(),
            'probation_ends_on' => $this->probation_ends_on?->toDateString(),
            'confirmed_on' => $this->confirmed_on?->toDateString(),
            'terminated_on' => $this->terminated_on?->toDateString(),
            'current_assignment' => $assignment ? new AssignmentResource($assignment) : null,
            'current_contract' => $contract ? new ContractResource($contract) : null,
            'contacts' => $this->contacts->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'relation' => $c->relation,
                'phone' => $c->phone,
            ])->values(),
            'compliance' => [
                'complete' => $this->compliance['complete'],
                'missing' => collect($this->compliance['missing'])->map(fn (DocumentType $type) => [
                    'code' => $type->code,
                    'name' => $type->name,
                ])->values(),
                'expired_count' => count($this->compliance['expired']),
            ],
        ];
    }
}
