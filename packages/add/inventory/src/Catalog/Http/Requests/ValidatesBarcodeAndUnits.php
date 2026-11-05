<?php

namespace Add\Inventory\Catalog\Http\Requests;

use Add\Inventory\Catalog\Models\Item;
use Illuminate\Validation\Rule;

/**
 * An item's barcode, image and packs. A pack is never the main unit, never
 * listed twice, and holds more than nothing of the main unit; whether a
 * pack already used by the ledger may change is the service's to say.
 */
trait ValidatesBarcodeAndUnits
{
    /**
     * @return array<string, mixed>
     */
    protected function barcodeAndUnitRules(?Item $item, mixed $mainUnitId): array
    {
        $sometimes = $item === null ? [] : ['sometimes'];

        return [
            'barcode' => [...$sometimes, 'nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z\-]+$/', Rule::unique('inv_items', 'barcode')->ignore($item)],
            'image_url' => [...$sometimes, 'nullable', 'string', 'url', 'max:2048'],
            'units' => ['sometimes', 'array', 'max:20'],
            'units.*.unit_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('inv_units', 'id'),
                ...(is_numeric($mainUnitId) ? [Rule::notIn([(int) $mainUnitId])] : []),
            ],
            'units.*.factor' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
        ];
    }
}
