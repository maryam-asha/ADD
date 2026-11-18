<?php

namespace App\Http\Requests\Admin\Reception;

use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class StoreGuestVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `name` is needed only when the phone has no account
     * (GuestVisitService::register() says so).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'host_user_id' => ['required', 'integer', 'exists:users,id'],
            'phone' => ['required', 'string', new PhoneNumber],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
