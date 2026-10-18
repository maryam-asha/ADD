<?php

namespace Add\Hr\Recruitment\Http\Requests;

use Add\Hr\Core\Http\Requests\HireEmployeeRequest;
use Add\Hr\Recruitment\Models\Application;

/**
 * The same hire as any other (HireEmployeeRequest), pre-filled from the
 * application: the candidate's name and contacts, the offer's start date,
 * and the requisition's unit, position, grade, employment type and hiring
 * manager. Anything sent wins.
 */
class HireCandidateRequest extends HireEmployeeRequest
{
    protected function prepareForValidation(): void
    {
        /** @var Application $application */
        $application = $this->route('application')->loadMissing(['candidate', 'liveOffer', 'opening.requisition']);
        $requisition = $application->opening->requisition;

        // The candidate's details make a new person; an existing account
        // brings its own (the PersonStore may refuse them as overrides).
        $person = $this->filled('user_id') ? [] : [
            'name' => ['ar' => $application->candidate->name],
            'phone' => $application->candidate->phone,
            'personal_email' => $application->candidate->email,
        ];

        $this->merge(array_replace_recursive([
            ...$person,
            'hired_on' => $application->liveOffer?->start_on?->toDateString(),
            'contract' => ['type' => 'open_ended'],
            'assignment' => array_filter([
                'org_unit_id' => $requisition->org_unit_id,
                'position_id' => $requisition->position_id,
                'job_grade_id' => $requisition->job_grade_id,
                'manager_id' => $requisition->hiring_manager_id,
                'employment_type' => $requisition->employment_type->value,
                'work_mode' => 'on_site',
            ], fn ($value) => $value !== null),
        ], $this->all()));
    }
}
