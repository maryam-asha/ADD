<?php

namespace Add\Hr\Recruitment\Http\Requests;

use Add\Hr\Core\Enums\EmploymentType;
use Add\Hr\Recruitment\Enums\OpeningVisibility;
use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The advert; opening and closing have their own actions.
 */
class UpdateJobOpeningRequest extends FormRequest
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
            ...TranslatableField::sometimes('title'),
            'description' => ['nullable', 'array'],
            'description.ar' => ['required_with:description', 'string', 'max:10000'],
            'description.en' => ['nullable', 'string', 'max:10000'],
            'employment_type' => ['sometimes', Rule::enum(EmploymentType::class)],
            'work_location_id' => ['nullable', 'integer', Rule::exists('hr_work_locations', 'id')],
            'visibility' => ['sometimes', Rule::enum(OpeningVisibility::class)],
            'closes_on' => ['nullable', 'date'],
        ];
    }
}
