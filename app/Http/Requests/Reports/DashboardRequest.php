<?php

namespace App\Http\Requests\Reports;

/** A dashboard section's filters: the period window and optionally one branch. */
class DashboardRequest extends ReportPeriodRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ];
    }
}
