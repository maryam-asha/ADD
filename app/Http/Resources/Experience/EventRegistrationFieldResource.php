<?php

namespace App\Http\Resources\Experience;

use App\Domain\Experience\Models\EventRegistrationField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventRegistrationField
 */
class EventRegistrationFieldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->translate('label', $locale),
            'placeholder' => $this->translate('placeholder', $locale),
            'type' => $this->type->value,
            'options' => $this->when($this->options !== null, fn () => collect($this->options)->map(fn ($opt) => [
                'value' => $opt['value'],
                'label' => $opt['label'][$locale] ?? $opt['label']['en'] ?? $opt['value'],
            ])->values()),
            'validation' => $this->validation,
            'is_required' => (bool) $this->is_required,
            'is_active' => (bool) $this->is_active,
            'order' => $this->order,
        ];
    }
}
