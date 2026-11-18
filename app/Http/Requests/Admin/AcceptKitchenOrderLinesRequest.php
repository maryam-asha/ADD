<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST admin/kitchen/orders/{order}/accept. With no body the order is
 * accepted as it was sent. `lines` names what the café can make of the lines
 * it is short of — `id` the line (the order's `lines.*.id`), `quantity`
 * fewer than ordered, or 0 to take the item off; a line not named is made as
 * ordered (KitchenOrderService::accept()).
 */
class AcceptKitchenOrderLinesRequest extends FormRequest
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
            'lines' => ['sometimes', 'array'],
            'lines.*.id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * Line id => quantity the café makes.
     *
     * @return array<int, int>
     */
    public function quantities(): array
    {
        return collect($this->validated('lines') ?? [])
            ->mapWithKeys(fn (array $line) => [(int) $line['id'] => (int) $line['quantity']])
            ->all();
    }
}
