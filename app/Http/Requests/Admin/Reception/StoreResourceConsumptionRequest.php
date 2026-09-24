<?php

namespace App\Http\Requests\Admin\Reception;

use Illuminate\Foundation\Http\FormRequest;

class StoreResourceConsumptionRequest extends FormRequest
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
            'user_id' => ['required_without:company_id', 'prohibits:company_id', 'integer', 'exists:users,id'],
            'company_id' => ['required_without:user_id', 'integer', 'exists:companies,id'],
            'resource_type_id' => ['required', 'integer', 'exists:resource_types,id'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
