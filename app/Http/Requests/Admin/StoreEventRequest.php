<?php

namespace App\Http\Requests\Admin;

use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventRequest extends FormRequest
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
        if ($this->has('room_id') && ! $this->has('space_id')) {
            $this->merge([
                'space_id' => $this->input('room_id'),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(
            TranslatableField::rules('title'),
            TranslatableField::rules('description', required: false),
            [
                'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
                'space_id' => ['nullable', 'integer', 'exists:spaces,id'],
                'room_id' => ['nullable', 'integer', 'exists:spaces,id'],
                'room' => ['nullable', 'string', 'max:100'],
                'category_id' => ['nullable', 'integer', 'exists:event_categories,id'],
                'start_at' => ['required', 'date'],
                'end_at' => ['required', 'date', 'after:start_at'],
                'location' => ['nullable', 'string', 'max:255'],
                'capacity' => ['nullable', 'integer', 'min:1'],
                'is_free' => ['nullable', 'boolean'],
                'price_points' => ['nullable', 'integer', 'min:0'],
                'price' => ['nullable', 'numeric', 'min:0'],
                'currency' => ['nullable', 'string', 'in:USD,SYP'],
                'image_url' => ['nullable', 'string', 'max:1000'],
                'status' => ['nullable', 'string', 'in:draft,published,cancelled'],
                'order' => ['nullable', 'integer', 'min:0'],
            ]
        );
    }
}
