<?php

namespace App\Http\Requests\Member;

use App\Domain\Kitchen\Enums\DeliveryType;
use App\Domain\Kitchen\Enums\OrderPaymentChoice;
use App\Domain\Membership\Enums\OwnerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An order to the kitchen: items and quantities; the scanned place's QR if
 * there was one; where to bring it when there was not; which bill, when
 * the member holds several checked-in bookings; and how it is paid
 * (`payment.method`, and the wallet to spend from when it is `wallet`).
 * Whether the method, the delivery and the bill fit the member is
 * KitchenOrderService's to say.
 *
 * Sent again with `order_id` — an order of theirs held for its card — only
 * `payment` is read: nothing is created, and `lines`, `qr`, `delivery` and
 * `bill` are ignored (2026-11-14 spec, decision 3).
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
            'order_id' => ['nullable', 'integer'],
            'lines' => ['required_without:order_id', 'array', 'min:1', 'max:20'],
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
            'payment' => ['required', 'array'],
            'payment.method' => ['required', 'string', Rule::enum(OrderPaymentChoice::class)],
            // The wallet to spend from — the member's own or a company's they may spend.
            'payment.wallet_owner_type' => ['nullable', 'required_with:payment.wallet_owner_id', Rule::enum(OwnerType::class)],
            'payment.wallet_owner_id' => ['nullable', 'required_with:payment.wallet_owner_type', 'integer'],
        ];
    }
}
