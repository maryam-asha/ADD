<?php

namespace Add\Hr\Timesheets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new split from a date; its lines sum to 100.
 */
class ReplaceCostAllocationRequest extends FormRequest
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
            'effective_from' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.cost_center_id' => ['required', 'integer', 'distinct', Rule::exists('hr_cost_centers', 'id')->where('is_active', true)],
            'lines.*.percent' => ['required', 'numeric', 'gt:0', 'max:100'],
        ];
    }
}
