<?php

namespace Add\Hr\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PayRunRequest extends FormRequest
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
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
