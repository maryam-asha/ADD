<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'contract_ref' => ['sometimes', 'string', 'max:255'],
            'payment_terms_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ];
    }
}
