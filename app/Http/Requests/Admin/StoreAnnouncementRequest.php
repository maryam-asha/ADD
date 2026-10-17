<?php

namespace App\Http\Requests\Admin;

use App\Domain\Ecosystem\Enums\AnnouncementPlacement;
use App\Domain\Ecosystem\Enums\AnnouncementType;
use App\Http\Requests\Concerns\InterpretsLocalTimes;
use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    use InterpretsLocalTimes;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->interpretLocalTimes(['starts_at', 'ends_at']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(AnnouncementType::class)],
            // Left out, it is `all`; never null.
            'placement' => ['sometimes', Rule::enum(AnnouncementPlacement::class)],
            ...TranslatableField::rules('subtitle', required: false),
            ...TranslatableField::rules('title', required: false),
            ...TranslatableField::rules('description', required: false),
            ...TranslatableField::rules('cta_label', required: false),
            'image_url' => ['required', 'string', 'max:2048'],
            'link_url' => ['nullable', 'string', 'max:2048', 'url'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
