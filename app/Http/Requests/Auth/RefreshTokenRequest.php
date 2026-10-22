<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Auth\Concerns\AcceptsDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

class RefreshTokenRequest extends FormRequest
{
    use AcceptsDeviceToken;

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
            'refresh_token' => ['required', 'string'],
        ] + $this->deviceTokenRules();
    }
}
