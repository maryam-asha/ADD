<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Enums\ResourceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreResourceRequest extends FormRequest
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
            'space_id' => ['required', 'integer', 'exists:spaces,id'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::enum(ResourceCategory::class)],
            'quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
