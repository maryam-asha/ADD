<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class BrowseEventsRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:100'],
            'free' => ['nullable'],
            'is_free' => ['nullable'],
            'price_type' => ['nullable', 'string', 'in:free,paid'],
            'type' => ['nullable', 'string', 'in:free,paid'],
            'cancelled' => ['nullable', 'boolean'],
            'is_cancelled' => ['nullable', 'boolean'],
            'category_id' => ['nullable', 'integer', 'exists:event_categories,id'],
            'category_slug' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
