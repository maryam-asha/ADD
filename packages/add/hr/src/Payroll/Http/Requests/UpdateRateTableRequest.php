<?php

namespace Add\Hr\Payroll\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The kind is fixed once versions exist.
 */
class UpdateRateTableRequest extends FormRequest
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
            'code' => ['sometimes', 'string', 'alpha_dash', 'max:32', Rule::unique('hr_rate_tables', 'code')->ignore($this->route('rateTable'))],
            ...TranslatableField::sometimes('name'),
        ];
    }
}
