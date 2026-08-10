<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rate_usd_to_syp' => ['required', 'numeric', 'gt:0'],
            'effective_from' => ['required', 'date'],
        ];
    }
}
