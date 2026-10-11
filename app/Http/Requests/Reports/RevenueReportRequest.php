<?php

namespace App\Http\Requests\Reports;

/** The revenue report's filters: the period window and optionally one provider. */
class RevenueReportRequest extends ReportPeriodRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'provider_id' => ['nullable', 'integer', 'exists:providers,id'],
        ];
    }
}
