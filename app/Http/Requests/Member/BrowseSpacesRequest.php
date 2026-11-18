<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class BrowseSpacesRequest extends FormRequest
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
            'space_type' => ['sometimes', 'nullable', 'string'],
            'min_capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
            'amenity' => ['sometimes', 'nullable', 'string'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
