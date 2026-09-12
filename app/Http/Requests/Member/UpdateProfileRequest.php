<?php

namespace App\Http\Requests\Member;

use App\Domain\Identity\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bio' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:120'],
            'avatar_url' => [
                'nullable',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! filter_var($value, FILTER_VALIDATE_URL) && ! preg_match('#^/?storage/#', (string) $value) && ! preg_match('#^(uploads|users)/#', (string) $value)) {
                        $fail(__('validation.url', ['attribute' => $attribute]));
                    }
                },
            ],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'job_title' => ['nullable', 'string', 'max:120'],
            'company_name' => ['nullable', 'string', 'max:120'],
            'industry' => ['nullable', 'string', 'max:120'],
            'linkedin_url' => ['nullable', 'url'],
            'instagram_url' => ['nullable', 'url'],
            'behance_url' => ['nullable', 'url'],
            'website_url' => ['nullable', 'url'],
        ];
    }
}
