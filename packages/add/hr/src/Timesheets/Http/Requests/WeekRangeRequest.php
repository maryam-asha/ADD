<?php

namespace Add\Hr\Timesheets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A report range of at most a year.
 */
class WeekRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from', 'before_or_equal:'.date('Y-m-d', strtotime('+366 days', strtotime((string) $this->input('from')) ?: time()))],
            'org_unit_id' => ['nullable', 'integer'],
            'cost_center_id' => ['nullable', 'integer'],
            'format' => ['nullable', 'in:json,csv'],
        ];
    }
}
