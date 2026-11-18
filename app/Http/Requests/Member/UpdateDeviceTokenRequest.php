<?php

namespace App\Http\Requests\Member;

use App\Http\Requests\Auth\Concerns\AcceptsDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceTokenRequest extends FormRequest
{
    use AcceptsDeviceToken;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fcm_token' => ['required_without:token', 'string', 'max:500'],
            'token' => ['required_without:fcm_token', 'string', 'max:500'],
        ] + $this->deviceTokenRules();
    }
}
