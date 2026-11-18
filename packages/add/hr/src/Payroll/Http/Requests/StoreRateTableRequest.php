<?php

namespace Add\Hr\Payroll\Http\Requests;

use Add\Hr\Payroll\Enums\RateTableKind;
use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRateTableRequest extends FormRequest
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
            'code' => ['required', 'string', 'alpha_dash', 'max:32', Rule::unique('hr_rate_tables', 'code')],
            ...TranslatableField::rules('name'),
            'kind' => ['required', Rule::enum(RateTableKind::class)],
        ];
    }
}
