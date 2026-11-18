<?php

namespace App\Http\Requests\Admin;

use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filters for the lease lists (admin, and the provider's after it scopes its own). */
class ListOfficeLeasesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_id' => ['nullable', 'integer'],
            'provider_id' => ['nullable', 'integer'],
            'space_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(OfficeLeaseStatus::class)],
            'ending_within_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
