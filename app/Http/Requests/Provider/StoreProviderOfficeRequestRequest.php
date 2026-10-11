<?php

namespace App\Http\Requests\Provider;

use App\Domain\Providers\Enums\Payer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A provider's office request: a new client company (no company_id) or
 * another office for one of its companies. The provider always says who
 * pays the rent.
 */
class StoreProviderOfficeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'prospect_name' => ['required', 'string', 'max:255'],
            'contact' => ['required', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer'],
            'payer' => ['required', Rule::enum(Payer::class)],
        ];
    }
}
