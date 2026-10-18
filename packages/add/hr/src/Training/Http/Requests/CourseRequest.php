<?php

namespace Add\Hr\Training\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Add\Hr\Training\Enums\Delivery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating (POST) or changing (PATCH) a catalogue course.
 */
class CourseRequest extends FormRequest
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
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'alpha_dash', 'max:32', Rule::unique('hr_courses', 'code')->ignore($this->route('course'))],
            ...($creating ? TranslatableField::rules('title') : TranslatableField::sometimes('title')),
            'description' => ['nullable', 'array'],
            'description.ar' => ['required_with:description', 'string', 'max:5000'],
            'description.en' => ['nullable', 'string', 'max:5000'],
            'provider' => ['nullable', 'string', 'max:255'],
            'delivery' => [$creating ? 'required' : 'sometimes', Rule::enum(Delivery::class)],
            'duration_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'required_with:cost', 'string', 'size:3'],
            ...TranslatableField::rules('certification_name', false),
            'certification_valid_months' => ['nullable', 'integer', 'min:1', 'max:240'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
