<?php

namespace Add\Inventory\Assets\Http\Requests;

use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Support\Rules\PlaceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterAssetRequest extends FormRequest
{
    use AssetDetailRules;
    use DecidesDraft;

    public function messages(): array
    {
        return ['location.prohibited' => __('inventory::assets.intangible_has_no_place')];
    }

    private function registersIntangible(): bool
    {
        $id = $this->input('item_id');

        return is_numeric($id) && (bool) Item::query()->with('category')->find($id)?->isIntangible();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', Rule::exists('inv_items', 'id')],
            // An intangible asset sits nowhere; any other needs its place.
            ...($this->registersIntangible() ? ['location' => ['prohibited']] : PlaceRules::assetLocation()),
            // Identical assets registered together, one code each.
            'count' => ['sometimes', 'integer', 'min:1', 'max:'.config('inventory.assets.max_batch')],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('inv_assets', 'code')],
            ...$this->detailRules(),
            // With `count`: one per unit, in the order they are made.
            ...$this->serialRules(),
            ...$this->draftRules(),
        ];
    }
}
