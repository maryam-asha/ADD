<?php

namespace App\Http\Requests\Admin;

use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Profile fields only — status and role are separate, deliberately
     * distinct actions (see UpdateUserStatusRequest / AssignRoleRequest).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', Rule::unique('users', 'phone')->ignore($userId), new PhoneNumber],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($userId)],
        ];
    }
}
