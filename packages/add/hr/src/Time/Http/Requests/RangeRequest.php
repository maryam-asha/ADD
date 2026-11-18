<?php

namespace Add\Hr\Time\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A date range of at most 62 days, with the usual list filters.
 */
class RangeRequest extends FormRequest
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
            'to' => ['required', 'date', 'after_or_equal:from', 'before_or_equal:'.$this->limit()],
            'employee_id' => ['nullable', 'integer'],
            'org_unit_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:24'],
            'type' => ['nullable', 'string', 'max:24'],
        ];
    }

    private function limit(): string
    {
        $from = strtotime((string) $this->input('from')) ?: time();

        return date('Y-m-d', strtotime('+61 days', $from));
    }
}
