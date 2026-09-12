<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BroadcastNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'array'],
            'title.en' => ['required', 'string', 'max:255'],
            'title.ar' => ['required', 'string', 'max:255'],
            'body' => ['required', 'array'],
            'body.en' => ['required', 'string', 'max:1000'],
            'body.ar' => ['required', 'string', 'max:1000'],
            'target' => ['required', 'string', 'in:all,role,users'],
            'role_name' => ['required_if:target,role', 'string', 'exists:roles,name'],
            'user_ids' => ['required_if:target,users', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
