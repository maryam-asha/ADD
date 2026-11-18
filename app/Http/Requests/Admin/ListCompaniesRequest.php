<?php

namespace App\Http\Requests\Admin;

use App\Domain\Identity\Enums\CompanyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filters for the admin company list; `direct` = companies ADD deals with itself (no provider). */
class ListCompaniesRequest extends FormRequest
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
            'provider_id' => ['nullable', 'integer', 'exists:providers,id', 'prohibits:direct'],
            'direct' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::enum(CompanyStatus::class)],
            'branch_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // Pickers (dropdowns) need every company, not a page of them.
            'all' => ['nullable', 'boolean', 'prohibits:per_page'],
        ];
    }
}
