<?php

namespace Add\Hr\Core\Http\Requests;

use Add\Hr\Core\Http\Requests\Concerns\PersonalFieldRules;
use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Personal data only. Job, contract, dates and status change through
 * employment events, never here — anything else sent is ignored.
 */
class UpdateEmployeeRequest extends FormRequest
{
    use PersonalFieldRules;

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
            ...TranslatableField::sometimes('name'),
            ...$this->personalFieldRules($this->route('employee')?->id),
        ];
    }
}
