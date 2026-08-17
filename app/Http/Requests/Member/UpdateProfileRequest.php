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
            'avatar_url' => ['nullable', 'url'],
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
