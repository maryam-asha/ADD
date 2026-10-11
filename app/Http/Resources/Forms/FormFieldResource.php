<?php

namespace App\Http\Resources\Forms;

use App\Domain\Forms\Models\FormField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One form question, for every owner — the admin editor and the member's
 * form render from the same shape. docs/api/form-field-types.md is the
 * client contract for it.
 *
 * @mixin FormField
 */
class FormFieldResource extends JsonResource
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
