<?php

namespace App\Http\Requests\Admin;

use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contract_ref' => ['required', 'string', 'max:255'],
            'payment_terms_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],

            // The provider's first sign-in account, created with it: a user
            // holding the `provider` role, linked in the same transaction.
            'account' => ['required', 'array'],
            'account.name' => ['required', 'string', 'max:255'],
            'account.email' => ['required', 'email', 'unique:users,email'],
            'account.phone' => ['required', 'string', 'unique:users,phone', new PhoneNumber],
            'account.password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
