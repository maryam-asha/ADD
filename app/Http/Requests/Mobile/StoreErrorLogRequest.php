<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ingests a client-reported error/crash from the member mobile app. No auth
 * is required — crashes can happen before login (e.g. on the login/splash
 * screen itself), so this cannot depend on a session existing yet
 * (docs/superpowers/specs/2026-08-11-mobile-error-logging-design.md).
 */
class StoreErrorLogRequest extends FormRequest
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
            'error_type' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'stack_trace' => ['nullable', 'string', 'max:20000'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'build_number' => ['nullable', 'string', 'max:50'],
            'platform' => ['nullable', 'in:android,ios'],
            'os' => ['nullable', 'string', 'max:100'],
            'device' => ['nullable', 'string', 'max:150'],
            'screen' => ['nullable', 'string', 'max:150'],
            'user_id' => ['nullable', 'integer'],
            'session_id' => ['nullable', 'string', 'max:100'],
            'occurred_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
