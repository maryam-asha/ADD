<?php

namespace Add\Inventory\Catalog\Http\Requests;

use Add\Inventory\Catalog\Models\Unit;
use Add\Inventory\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitRequest extends FormRequest
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
        /** @var Unit|null $unit */
        $unit = $this->route('unit');
        $presence = $unit === null ? 'required' : 'sometimes';

        return [
            ...($unit === null ? TranslatableField::rules('name') : TranslatableField::sometimes('name')),
            'code' => [$presence, 'string', 'max:20', 'regex:/^[a-z0-9_]+$/', Rule::unique('inv_units', 'code')->ignore($unit)],
            'allows_fractions' => ['sometimes', 'boolean'],
        ];
    }
}
