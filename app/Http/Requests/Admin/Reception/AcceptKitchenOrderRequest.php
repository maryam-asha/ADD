<?php

namespace App\Http\Requests\Admin\Reception;

use App\Rules\AmountFitsCurrency;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reception letting an order through to the kitchen. For a cash order the
 * money handed over comes with it, as SettleAtReceptionRequest takes it:
 * `amount` omitted = exactly what is due, `currency` omitted = the base
 * currency. Both are ignored for an order that is already paid.
 */
class AcceptKitchenOrderRequest extends FormRequest
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
            // A plain decimal: `numeric` alone lets `1e3` through, which
            // bcmath refuses (docs/decisions/unified-payments.md).
            'amount' => ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,6})?$/', 'min:0.01', new AmountFitsCurrency($this->input('currency'))],
            'currency' => ['nullable', 'string', 'exists:currencies,code'],
        ];
    }
}
