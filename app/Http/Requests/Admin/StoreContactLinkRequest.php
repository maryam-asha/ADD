<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\DropsNullDefaults;
use Illuminate\Foundation\Http\FormRequest;

class StoreContactLinkRequest extends FormRequest
{
    use DropsNullDefaults;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->dropNullDefaults(['sort_order', 'is_visible']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'max:255'],
            'value' => ['required', 'string', 'max:2048', 'not_regex:/^\s*(javascript|data|vbscript):/i'],
            'label' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_visible' => ['nullable', 'boolean'],
        ];
    }
}
