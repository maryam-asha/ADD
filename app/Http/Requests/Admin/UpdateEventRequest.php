<?php

namespace App\Http\Requests\Admin;

use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEventRequest extends FormRequest
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
        return array_merge(
            TranslatableField::rules('title', required: false),
            TranslatableField::rules('description', required: false),
            [
                'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
                'category_id' => ['nullable', 'integer', 'exists:event_categories,id'],
                'start_at' => ['sometimes', 'required', 'date'],
                'end_at' => ['sometimes', 'required', 'date', 'after:start_at'],
                'location' => ['nullable', 'string', 'max:255'],
                'capacity' => ['nullable', 'integer', 'min:1'],
                'is_free' => ['nullable', 'boolean'],
                'price' => ['nullable', 'numeric', 'min:0'],
                'currency' => ['nullable', 'string', 'in:USD,SYP'],
                'image_url' => ['nullable', 'string', 'max:1000'],
                'status' => ['nullable', 'string', 'in:draft,published,cancelled'],
                'order' => ['nullable', 'integer', 'min:0'],
            ]
        );
    }
}
