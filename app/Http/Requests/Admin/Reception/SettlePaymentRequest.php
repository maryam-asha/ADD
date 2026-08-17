<?php

namespace App\Http\Requests\Admin\Reception;

use App\Domain\Finance\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettlePaymentRequest extends FormRequest
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
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
        ];
    }
}
