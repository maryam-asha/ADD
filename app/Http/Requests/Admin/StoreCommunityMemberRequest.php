<?php

namespace App\Http\Requests\Admin;

use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommunityMemberRequest extends FormRequest
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
        return array_merge(
            TranslatableField::rules('name'),
            TranslatableField::rules('company', required: false),
            TranslatableField::rules('title', required: false),
            TranslatableField::rules('bio', required: false),
            TranslatableField::rules('long_bio', required: false),
            TranslatableField::rules('location', required: false),
            [
                'category' => ['required', Rule::in(['pioneers', 'growth_partners', 'investors', 'impact_partners'])],
                'year_joined' => ['nullable', 'integer', 'min:2000', 'max:'.(now()->year + 1)],
                'photo_url' => ['nullable', 'url'],
                'social_links' => ['nullable', 'array'],
                'skills' => ['nullable', 'array'],
                'skills.*' => ['string'],
                'order' => ['nullable', 'integer', 'min:0'],
                'published' => ['nullable', 'boolean'],
            ]
        );
    }
}
