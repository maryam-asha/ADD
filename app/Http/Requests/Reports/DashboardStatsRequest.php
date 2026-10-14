<?php

namespace App\Http\Requests\Reports;

use App\Domain\Foundation\Enums\SpaceType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A statistics card's filters: the dashboard's period and branch, and a space
 * type where the card counts bookings. A year at most — the daily cards
 * return a point per day.
 */
class DashboardStatsRequest extends DashboardRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'space_type' => ['nullable', Rule::enum(SpaceType::class)],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $from = $this->input('from');
                $to = $this->input('to') ?? now()->toDateString();

                if (is_string($from) && is_string($to) && strtotime($to) - strtotime($from) > 366 * 86400) {
                    $validator->errors()->add('to', __('api.dashboard.period_too_long'));
                }
            },
        ];
    }
}
