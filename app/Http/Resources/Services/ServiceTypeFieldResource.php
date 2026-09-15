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
        return [
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->translate('label'),
            'placeholder' => $this->translate('placeholder'),
            'type' => $this->type->value,
            'options' => $this->when($this->options !== null, function () {
                return collect($this->options)->map(fn ($opt) => [
                    'value' => $opt['value'],
                    'label' => $opt['label'][app()->getLocale()]
                           ?? $opt['label']['en']
                           ?? $opt['value'],
                ]);
            }),
            'validation' => $this->validation,
            'is_required' => $this->is_required,
            'order' => $this->order,
        ];
    }
}
