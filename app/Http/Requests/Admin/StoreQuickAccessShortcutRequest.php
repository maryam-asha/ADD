<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\DropsNullDefaults;
use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuickAccessShortcutRequest extends FormRequest
{
    use DropsNullDefaults;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->dropNullDefaults(['order', 'is_active']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('quick_access_shortcuts', 'key')->ignore($this->route('quickAccessShortcut'))],
            ...TranslatableField::rules('label'),
            // An uploaded image (POST admin/media/upload) or any image URL.
            'icon_url' => ['required', 'string', 'max:2048'],
            // One of the member app's own static routes, e.g. /cafe-menu.
            'route' => ['required', 'string', 'max:255', 'starts_with:/'],
            'order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
