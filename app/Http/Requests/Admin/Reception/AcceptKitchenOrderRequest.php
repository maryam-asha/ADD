<?php

namespace App\Http\Requests\Admin\Reception;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reception letting an order through to the kitchen. For a cash order the
 * desk takes exactly what is due, in `currency` (omitted: the base
 * currency); there is no `amount` — change is the desk's, never points
 * (ReceptionOrderReview::accept). Ignored for an order that is already paid.
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
            'currency' => ['nullable', 'string', 'exists:currencies,code'],
            // For the kitchen only — where the member is, or any other
            // detail; the member never sees it.
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
