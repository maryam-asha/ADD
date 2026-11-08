<?php

namespace Add\Inventory\Assets\Http\Requests;

use Add\Inventory\Assets\Enums\DepreciationMethod;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Inventory;
use Add\Inventory\Support\Decimal;
use Add\Inventory\Support\Rules\FileUrl;
use Add\Inventory\Support\Rules\PlaceRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Several assets put in one place at once — what a room holds, entered in
 * one go: the place and what the purchase shares (date, supplier, its
 * invoice, the currency, a note) once, then a line per asset item, each
 * with its count and what differs between them. A serial number is for a
 * line of one; a line's cost needs the currency.
 */
class RegisterAssetBatchRequest extends FormRequest
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
            ...PlaceRules::assetLocation(),
            'purchased_on' => ['nullable', 'date_format:Y-m-d'],
            'in_service_on' => ['nullable', 'date_format:Y-m-d'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:60'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['required', ...FileUrl::rules()],
            'currency' => ['nullable', ...Inventory::currencyRules()],
            'notes' => ['nullable', 'string', 'max:5000'],

            'lines' => ['required', 'array', 'min:1', 'max:'.config('inventory.assets.max_lines')],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('inv_items', 'id')],
            'lines.*.count' => ['sometimes', 'integer', 'min:1', 'max:'.config('inventory.assets.max_batch')],
            'lines.*.serial_number' => ['nullable', 'string', 'max:100'],
            'lines.*.purchase_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'lines.*.depreciation_method' => ['nullable', Rule::in(DepreciationMethod::enabledValues())],
            'lines.*.useful_life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'lines.*.salvage_value' => ['nullable', 'numeric', 'min:0'],
            'lines.*.warranty_until' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Each line's own checks, named on that line: an active asset item, a
     * serial number only for one, and salvage within its cost.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $lines = $this->input('lines');

            if (! is_array($lines) || $validator->errors()->has('lines')) {
                return;
            }

            $purchased = $this->input('purchased_on');
            $inService = $this->input('in_service_on');

            if (is_string($purchased) && is_string($inService) && $inService < $purchased && ! $validator->errors()->has('in_service_on')) {
                $validator->errors()->add('in_service_on', __('inventory::assets.in_service_before_purchase'));
            }

            $costed = array_filter($lines, fn ($line) => is_array($line) && is_numeric($line['purchase_cost'] ?? null));

            if ($costed !== [] && blank($this->input('currency'))) {
                $validator->errors()->add('currency', __('inventory::assets.currency_for_cost'));
            }

            $items = Item::query()->with('category')->findMany(array_filter(array_column($lines, 'item_id'), 'is_numeric'))->keyBy('id');

            foreach ($lines as $i => $line) {
                $item = is_array($line) ? $items->get($line['item_id'] ?? null) : null;

                if ($item !== null && ! $item->isAsset()) {
                    $validator->errors()->add("lines.{$i}.item_id", __('inventory::assets.not_asset'));
                } elseif ($item !== null && $item->isIntangible()) {
                    $validator->errors()->add("lines.{$i}.item_id", __('inventory::assets.intangible_not_in_place'));
                } elseif ($item !== null && ! $item->is_active) {
                    $validator->errors()->add("lines.{$i}.item_id", __('inventory::assets.item_inactive'));
                }

                if (filled($line['serial_number'] ?? null) && (int) ($line['count'] ?? 1) > 1) {
                    $validator->errors()->add("lines.{$i}.serial_number", __('inventory::assets.serial_for_one'));
                }

                $cost = $line['purchase_cost'] ?? null;
                $salvage = $line['salvage_value'] ?? null;

                if (is_numeric($salvage) && (! is_numeric($cost) || Decimal::cmp($salvage, $cost) > 0)) {
                    $validator->errors()->add("lines.{$i}.salvage_value", __('inventory::assets.salvage_over_cost'));
                }
            }
        });
    }
}
