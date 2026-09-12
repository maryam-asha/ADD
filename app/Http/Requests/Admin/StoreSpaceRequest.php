<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Enums\SpaceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSpaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('image_url') && ! $this->has('images') && $this->input('image_url') !== null) {
            $this->merge([
                'images' => [$this->input('image_url')],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
            'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
            'space_type' => ['required', Rule::enum(SpaceType::class)],
            'allocation_model' => ['nullable', Rule::enum(AllocationModel::class)],
            'is_lockable' => ['required', 'boolean'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'images' => ['nullable', 'array'],
            'images.*' => ['string', 'max:2048'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'pricing_currency' => ['nullable', Rule::exists('currencies', 'code')->where('is_active', true)],
        ];
    }
}
