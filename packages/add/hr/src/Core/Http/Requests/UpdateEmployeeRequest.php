<?php

namespace Add\Hr\Core\Http\Requests;

use Add\Hr\Hr;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Personal data only. Job, contract, dates and status change through
 * employment events, never here — anything else sent is ignored.
 */
class UpdateEmployeeRequest extends FormRequest
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
        return Hr::people()->updateRules($this->route('employee'));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['prohibited' => __('hr::core.person_field_on_account')];
    }
}
