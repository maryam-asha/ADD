<?php

namespace App\Http\Requests\Admin;

use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Foundation\Models\Branch;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The warehouse named as a branch's kitchen store: it sits in that branch,
 * may sell, and is not the main store.
 */
class UpdateKitchenStoreRequest extends FormRequest
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
        /** @var Branch $branch */
        $branch = $this->route('branch');

        return [
            'warehouse_id' => ['required', 'integer', 'exists:inv_warehouses,id', function (string $attribute, mixed $value, Closure $fail) use ($branch): void {
                $warehouse = Warehouse::query()->find($value);

                if ($warehouse === null) {
                    return;
                }

                if ($warehouse->place_type !== 'branch' || $warehouse->place_id !== $branch->id) {
                    $fail(__('api.kitchen.store_not_in_branch'));
                } elseif (! $warehouse->allows_sales) {
                    $fail(__('api.kitchen.store_not_sellable'));
                } elseif ($warehouse->is_primary) {
                    $fail(__('api.kitchen.store_is_primary'));
                }
            }],
        ];
    }
}
