<?php

namespace Add\Hr\Payroll\Http\Requests;

use Add\Hr\Hr;
use Illuminate\Foundation\Http\FormRequest;

class RequestLoanRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'currency' => ['required', ...Hr::currencyRules()],
            'installments' => ['required', 'integer', 'min:1', 'max:120'],
            'first_month' => ['nullable', 'date_format:Y-m'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
