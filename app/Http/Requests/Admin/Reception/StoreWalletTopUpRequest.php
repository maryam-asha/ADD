<?php

namespace App\Http\Requests\Admin\Reception;

use App\Domain\Finance\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWalletTopUpRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'user_id' => ['required_without:company_id', 'prohibits:company_id', 'integer', 'exists:users,id'],
            'company_id' => ['required_without:user_id', 'integer', 'exists:companies,id'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
