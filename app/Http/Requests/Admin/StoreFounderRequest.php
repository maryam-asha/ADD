<?php

namespace App\Http\Requests\Admin;

use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

class StoreFounderRequest extends FormRequest
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
            TranslatableField::rules('role', required: false),
            TranslatableField::rules('bio', required: false),
            [
                'photo_url' => ['nullable', 'url'],
                'linkedin_url' => ['nullable', 'url'],
                'twitter_url' => ['nullable', 'url'],
                'order' => ['nullable', 'integer', 'min:0'],
            ]
        );
    }
}
