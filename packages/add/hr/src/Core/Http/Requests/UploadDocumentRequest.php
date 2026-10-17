<?php

namespace Add\Hr\Core\Http\Requests;

use Add\Hr\Core\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Multipart: the file plus its type and dates. File types and size come
 * from config('hr.documents').
 */
class UploadDocumentRequest extends FormRequest
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
        $type = DocumentType::query()->find($this->input('document_type_id'));

        return [
            'document_type_id' => ['required', 'integer', Rule::exists('hr_document_types', 'id')->where('is_active', true)],
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', (array) config('hr.documents.mimes')),
                'max:'.(int) config('hr.documents.max_kb'),
            ],
            'issued_on' => ['nullable', 'date', 'before_or_equal:today'],
            'expires_on' => ['nullable', Rule::requiredIf((bool) $type?->has_expiry), 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
