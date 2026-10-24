<?php

namespace Add\Inventory\Assets\Http\Requests;

use Add\Inventory\Support\Rules\PlaceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterAssetRequest extends FormRequest
{
    use AssetDetailRules;

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
            'item_id' => ['required', 'integer', Rule::exists('inv_items', 'id')],
            ...PlaceRules::location(),
            // Identical assets registered together, one code each.
            'count' => ['sometimes', 'integer', 'min:1', 'max:'.config('inventory.assets.max_batch')],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('inv_assets', 'code')],
            ...$this->detailRules(),
        ];
    }
}
