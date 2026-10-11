<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Body of every auto-renew toggle (admin and provider). */
class UpdateAutoRenewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['auto_renew' => ['required', 'boolean']];
    }
}
