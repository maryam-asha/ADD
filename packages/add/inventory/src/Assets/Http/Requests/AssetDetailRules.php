<?php

namespace Add\Inventory\Assets\Http\Requests;

use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Inventory;
use Add\Inventory\Support\Decimal;
use Illuminate\Contracts\Validation\Validator;

/**
 * The descriptive and financial fields of an asset, shared by registering
 * and editing one. A cost needs its currency; salvage cannot exceed cost.
 */
trait AssetDetailRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function detailRules(string $presence = 'nullable'): array
    {
        $sometimes = $presence === 'sometimes' ? ['sometimes'] : [];

        return [
            'serial_number' => [...$sometimes, 'nullable', 'string', 'max:100'],
            'purchased_on' => [...$sometimes, 'nullable', 'date_format:Y-m-d'],
            'purchase_cost' => [...$sometimes, 'nullable', 'numeric', 'min:0', 'max:999999999999'],
            'currency' => [...$sometimes, 'nullable', 'required_with:purchase_cost', ...Inventory::currencyRules()],
            'supplier' => [...$sometimes, 'nullable', 'string', 'max:255'],
            'useful_life_months' => [...$sometimes, 'nullable', 'integer', 'min:1', 'max:1200'],
            'salvage_value' => [...$sometimes, 'nullable', 'numeric', 'min:0'],
            'warranty_until' => [...$sometimes, 'nullable', 'date_format:Y-m-d'],
            'notes' => [...$sometimes, 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Salvage against cost as they will stand: what was sent, else what
     * the asset already has.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Asset|null $asset */
            $asset = $this->route('asset');
            $cost = $this->has('purchase_cost') ? $this->input('purchase_cost') : $asset?->purchase_cost;
            $salvage = $this->has('salvage_value') ? $this->input('salvage_value') : $asset?->salvage_value;

            if (is_numeric($salvage) && (! is_numeric($cost) || Decimal::cmp($salvage, $cost) > 0)) {
                $validator->errors()->add('salvage_value', __('inventory::assets.salvage_over_cost'));
            }
        });
    }
}
