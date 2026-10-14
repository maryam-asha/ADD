<?php

namespace App\Http\Requests\Reports;

use App\Domain\Reports\Services\Dashboard\SpacesOverview;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The spaces section's filters: the dashboard's period and branch, and a
 * space type or one space. The period runs to a year at most — each day is
 * measured against its open hours.
 */
class DashboardSpacesRequest extends DashboardRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'space_type' => ['nullable', Rule::in(array_map(fn ($type) => $type->value, SpacesOverview::TYPES))],
            'space_id' => ['nullable', 'integer', 'exists:spaces,id'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                // The window as DashboardPeriod reads it: `to` defaults to
                // today, and a missing `from` never reaches past a month.
                $from = $this->input('from');
                $to = $this->input('to') ?? now()->toDateString();

                if (is_string($from) && is_string($to) && strtotime($to) - strtotime($from) > 366 * 86400) {
                    $validator->errors()->add('to', __('api.dashboard.period_too_long'));
                }
            },
        ];
    }
}
