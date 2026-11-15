<?php

namespace App\Http\Requests\Admin\Reception;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reception's reason for turning an order down; the member sees it. Shorter
 * than the kitchen's (RejectKitchenOrderRequest): it is read on a desk
 * screen and in a notice, not written at a counter.
 */
class RejectReceptionKitchenOrderRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:200'],
        ];
    }
}
