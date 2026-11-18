<?php

namespace App\Http\Requests\Services\Admin;

use App\Domain\Services\Enums\RequestMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $methods = collect(RequestMethod::cases())->map->value->all();
        $typeId = $this->route('serviceType')?->id;

        return [
            'service_axis_id' => ['sometimes', 'integer', 'exists:service_axes,id'],
            'slug' => ['sometimes', 'string', 'max:100', Rule::unique('service_types', 'slug')->ignore($typeId)],
            'name' => ['sometimes', 'array'],
            'name.en' => ['required_with:name', 'string', 'max:150'],
            'name.ar' => ['required_with:name', 'string', 'max:150'],
            'description' => ['nullable', 'array'],
            'description.en' => ['nullable', 'string', 'max:500'],
            'description.ar' => ['nullable', 'string', 'max:500'],
            // The "Explore ADD" page: its explanation, as HTML the app renders.
            'about' => ['nullable', 'array'],
            'about.en' => ['nullable', 'string', 'max:20000'],
            'about.ar' => ['nullable', 'string', 'max:20000'],
            'icon' => ['nullable', 'string', 'max:100'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'is_coming_soon' => ['sometimes', 'boolean'],
            'show_on_home' => ['sometimes', 'boolean'],
            'requires_location' => ['sometimes', 'boolean'],
            'requires_review' => ['sometimes', 'boolean'],
            'visibility' => ['sometimes', 'string', 'in:member,public,both'],
            'request_method' => ['sometimes', 'string', Rule::in($methods)],
            'request_config' => ['nullable', 'array'],
            'allowed_plan_module_ids' => ['nullable', 'array'],
            'allowed_plan_module_ids.*' => ['integer', 'exists:plan_modules,id'],
            'order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
