<?php

namespace App\Http\Requests\Member;

use App\Domain\Identity\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Either may be cleared, not both (see after()).
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
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

    /**
     * The account keeps a name: after this request, at least one of
     * name_ar / name_en (sent, or already stored) must be left.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['name_ar', 'name_en'])) {
                    return;
                }

                $user = $this->user();
                $ar = $this->has('name_ar') ? $this->input('name_ar') : $user->name_ar;
                $en = $this->has('name_en') ? $this->input('name_en') : $user->name_en;

                if (blank($ar) && blank($en)) {
                    $validator->errors()->add($this->has('name_ar') ? 'name_ar' : 'name_en', __('api.member.name_required'));
                }
            },
        ];
    }
}
