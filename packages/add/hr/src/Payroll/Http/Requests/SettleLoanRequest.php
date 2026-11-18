<?php

namespace Add\Hr\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SettleLoanRequest extends FormRequest
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
            'note' => ['required', 'string', 'max:255'],
        ];
    }
}
