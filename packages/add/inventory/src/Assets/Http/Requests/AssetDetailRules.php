<?php

namespace Add\Inventory\Assets\Http\Requests;

use Add\Inventory\Assets\Enums\DepreciationMethod;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Inventory;
use Add\Inventory\Support\Decimal;
use Add\Inventory\Support\Rules\FileUrl;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * The descriptive and financial fields of an asset, shared by registering
 * and editing one. A cost needs its currency; salvage cannot exceed cost;
 * an asset is not put in service before it is bought.
 */
trait AssetDetailRules
{
    use ValidatesSerials;

    /**
     * @return array<string, list<mixed>>
     */
    protected function detailRules(string $presence = 'nullable'): array
    {
        $sometimes = $presence === 'sometimes' ? ['sometimes'] : [];

        return [
            'serial_number' => [...$sometimes, 'nullable', 'string', 'max:100'],
            'purchased_on' => [...$sometimes, 'nullable', 'date_format:Y-m-d'],
            // When it was put in service: its depreciation starts then.
            'in_service_on' => [...$sometimes, 'nullable', 'date_format:Y-m-d'],
            'purchase_cost' => [...$sometimes, 'nullable', 'numeric', 'min:0', 'max:999999999999'],
            'currency' => [...$sometimes, 'nullable', 'required_with:purchase_cost', ...Inventory::currencyRules()],
            'supplier' => [...$sometimes, 'nullable', 'string', 'max:255'],
            // The supplier's invoice number, and the invoice uploaded.
            'reference' => [...$sometimes, 'nullable', 'string', 'max:60'],
            'attachments' => [...$sometimes, 'nullable', 'array', 'max:10'],
            'attachments.*' => ['required', ...FileUrl::rules()],
            'useful_life_months' => [...$sometimes, 'nullable', 'integer', 'min:1', 'max:1200'],
            'depreciation_method' => [...$sometimes, 'nullable', Rule::in(DepreciationMethod::enabledValues())],
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

            $purchased = $this->has('purchased_on') ? $this->input('purchased_on') : $asset?->purchased_on?->toDateString();
            $inService = $this->has('in_service_on') ? $this->input('in_service_on') : $asset?->in_service_on?->toDateString();

            if (is_string($purchased) && is_string($inService) && $inService < $purchased && ! $validator->errors()->has('in_service_on')) {
                $validator->errors()->add('in_service_on', __('inventory::assets.in_service_before_purchase'));
            }

            $this->checkDetailSerials($validator, $asset);
        });
    }

    /**
     * Registering: one serial, or a serial per unit — never both, and one
     * serial only for a single asset. Editing: its own serial is not a
     * duplicate of itself.
     */
    private function checkDetailSerials(Validator $validator, ?Asset $asset): void
    {
        $single = $this->input('serial_number');

        if ($asset !== null) {
            // Only a changed serial is checked: one shared before serials were
            // checked must not block every later edit of the asset.
            $unchanged = is_string($single) && mb_strtolower(trim($single)) === mb_strtolower(trim((string) $asset->serial_number));

            if (filled($single) && ! $unchanged) {
                $this->checkSerials($validator, [['key' => 'serial_number', 'item_id' => $asset->item_id, 'count' => 1, 'serials' => [$single], 'ignore' => $asset->id]]);
            }

            return;
        }

        $count = (int) $this->input('count', 1);
        $serials = $this->input('serial_numbers');

        if (filled($single) && $serials !== null) {
            $validator->errors()->add('serial_numbers', __('inventory::assets.serial_or_serials'));

            return;
        }

        if (filled($single) && $count > 1) {
            $validator->errors()->add('serial_number', __('inventory::assets.serial_for_one'));

            return;
        }

        $this->checkSerials($validator, [
            $serials !== null
                ? ['key' => 'serial_numbers', 'item_id' => $this->input('item_id'), 'count' => $count, 'serials' => $serials]
                : ['key' => 'serial_number', 'item_id' => $this->input('item_id'), 'count' => 1, 'serials' => filled($single) ? [$single] : []],
        ]);
    }
}
