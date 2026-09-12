<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fcm_token' => ['required_without:token', 'string', 'max:500'],
            'token' => ['required_without:fcm_token', 'string', 'max:500'],
            'platform' => ['nullable', 'string', 'in:ios,android,web'],
        ];
    }
}
