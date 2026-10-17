<?php

namespace Add\Hr\Core\Http\Requests;

use Add\Hr\Core\Enums\ContractType;
use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentTypeRequest extends FormRequest
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
            ...TranslatableField::sometimes('name'),
            'code' => ['sometimes', 'string', 'max:32', 'alpha_dash', Rule::unique('hr_document_types', 'code')->ignore($this->route('documentType'))],
            'is_required' => ['sometimes', 'boolean'],
            'contract_types' => ['nullable', 'array'],
            'contract_types.*' => ['distinct', Rule::enum(ContractType::class)],
            'has_expiry' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
