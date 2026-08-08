<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePrivateOfficeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 'contracted' is deliberately not a settable value here — it is only
     * reachable through Api\V1\Admin\CompanyController::store, which is the
     * one path that also creates the company row (PRD §5.3 pipeline order).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prospect_name' => ['sometimes', 'string', 'max:255'],
            'contact' => ['sometimes', 'string', 'max:255'],
            'quote_ref' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['requested', 'quoted'])],
        ];
    }
}
