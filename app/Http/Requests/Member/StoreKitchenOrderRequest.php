<?php

namespace App\Http\Requests\Member;

use App\Domain\Kitchen\Enums\DeliveryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An order to the kitchen: items and quantities; the scanned place's QR if
 * there was one; where to bring it when there was not; and which bill, when
 * the member holds several checked-in bookings. Whether the delivery and
 * the bill fit the member's session is KitchenOrderService's to say.
 */
class StoreKitchenOrderRequest extends FormRequest
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
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.sale_item_id' => ['required', 'integer', 'distinct', 'exists:sale_items,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            // The scanned text, `add-place:v1:…`.
            'qr' => ['nullable', 'string', 'max:255'],
            'delivery' => ['nullable', 'array'],
            // A desk is only ever named by its QR.
            'delivery.type' => ['required_with:delivery', 'string', Rule::in([DeliveryType::Space->value, DeliveryType::Pickup->value, DeliveryType::Note->value])],
            'delivery.space_id' => ['nullable', 'integer', 'exists:spaces,id'],
            'delivery.note' => ['nullable', 'string', 'max:200'],
            'bill' => ['nullable', 'array'],
            'bill.type' => ['required_with:bill', 'string', Rule::in(['booking', 'walkin'])],
            'bill.id' => ['required_with:bill', 'integer'],
        ];
    }
}
