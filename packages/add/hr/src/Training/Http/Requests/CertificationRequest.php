<?php

namespace Add\Hr\Training\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording (POST) or correcting (PATCH) a certification held by an
 * employee, such as one earned outside.
 */
class CertificationRequest extends FormRequest
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
        $creating = $this->isMethod('post');

        return [
            ...($creating ? TranslatableField::rules('name') : TranslatableField::sometimes('name')),
            'course_id' => ['nullable', 'integer', Rule::exists('hr_courses', 'id')],
            'issuer' => ['nullable', 'string', 'max:255'],
            'issued_on' => [$creating ? 'required' : 'sometimes', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
