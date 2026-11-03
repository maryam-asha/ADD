<?php

namespace App\Http\Requests\Dashboard;

use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A dashboard browser's Firebase token, registered or forgotten by the
 * account signed in to it.
 */
class DashboardDeviceTokenRequest extends FormRequest
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
            'token' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * The language the dashboard runs in (its `lang` header): this browser's
     * pushes are written in it. Without one, the account's.
     *
     * @return array<string, string>
     */
    public function deviceDetails(): array
    {
        $language = strtolower((string) $this->header('lang'));

        return in_array($language, SetLocaleFromHeader::SUPPORTED_LOCALES, true) ? ['language' => $language] : [];
    }
}
