<?php

namespace App\Http\Requests\Admin;

use App\Domain\Renewals\Enums\RenewalStopReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListStoppedRenewalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(['membership', 'lease'])],
            'provider_id' => ['nullable', 'integer'],
            'reason' => ['nullable', Rule::enum(RenewalStopReason::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
