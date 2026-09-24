<?php

namespace App\Http\Resources\Services;

use App\Domain\Services\Models\ServiceTypeField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceTypeField */
class ServiceTypeFieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->translate('label', $locale),
            'placeholder' => $this->translate('placeholder', $locale),
            'type' => $this->type->value,
            'options' => $this->when($this->options !== null, function () use ($locale) {
                return collect($this->options)->map(fn ($opt) => [
                    'value' => $opt['value'],
                    'label' => $opt['label'][$locale]
                           ?? $opt['label']['en']
                           ?? $opt['value'],
                ])->values();
            }),
            'validation' => $this->validation,
            'is_required' => (bool) $this->is_required,
            'is_active' => (bool) $this->is_active,
            'order' => $this->order,
        ];
    }
}
