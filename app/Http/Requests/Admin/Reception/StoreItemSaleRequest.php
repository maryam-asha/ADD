<?php

namespace App\Http\Requests\Admin\Reception;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reception selling stock to a member: what and how many, paid from their
 * points (`wallet`) or with money handed over at today's price, in
 * `currency` (default: the base currency). `warehouse_id` is where it is
 * taken from — by default the primary warehouse of the branch of the
 * operator's open cash register.
 */
class StoreItemSaleRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.sale_item_id' => ['required', 'integer', 'distinct', Rule::exists('sale_items', 'id')->where('is_active', true)],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'payment_method' => ['required', 'string', Rule::in([
                ...array_map(fn (PaymentMethod $method) => $method->value, PaymentMethod::atReception()),
                WalletMethod::KEY,
            ])],
            'currency' => ['nullable', 'string', 'exists:currencies,code'],
            // The warehouse it comes from; by default the primary one of the
            // branch of the operator's open cash register.
            'warehouse_id' => ['nullable', 'integer', Rule::exists('inv_warehouses', 'id')],
        ];
    }
}
