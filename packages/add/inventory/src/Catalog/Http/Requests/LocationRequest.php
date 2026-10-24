<?php

namespace Add\Inventory\Catalog\Http\Requests;

use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocationRequest extends FormRequest
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
        /** @var Location|null $location */
        $location = $this->route('location');

        return [
            ...($location === null ? TranslatableField::rules('name') : TranslatableField::sometimes('name')),
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('inv_locations', 'id'),
                ...($location === null ? [] : [Rule::notIn($location->id)]),
            ],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('inv_locations', 'code')->ignore($location)],
        ];
    }
}
