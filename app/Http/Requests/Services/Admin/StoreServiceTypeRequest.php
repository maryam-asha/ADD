<?php

namespace App\Http\Requests\Services\Admin;

use App\Domain\Services\Enums\RequestMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $methods = collect(RequestMethod::cases())->map->value->all();

        return [
            'service_axis_id' => ['required', 'integer', 'exists:service_axes,id'],
            'slug' => ['required', 'string', 'max:100', 'unique:service_types,slug'],
            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'max:150'],
            'name.ar' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'array'],
            'description.en' => ['nullable', 'string', 'max:500'],
            'description.ar' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'is_coming_soon' => ['boolean'],
            'requires_location' => ['boolean'],
            'requires_review' => ['boolean'],
            'visibility' => ['required', 'string', 'in:member,public,both'],
            'request_method' => ['required', 'string', Rule::in($methods)],
            'request_config' => ['nullable', 'array'],
            'allowed_plan_module_ids' => ['nullable', 'array'],
            'allowed_plan_module_ids.*' => ['integer', 'exists:plan_modules,id'],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
