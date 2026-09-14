<?php

namespace App\Http\Resources\Services;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Services\Models\ServiceRequest */
class ServiceRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'service_type'    => new ServiceTypeResource($this->whenLoaded('serviceType')),
            'status'          => $this->status->value,
            'location_source' => $this->location_source?->value,
            'branch'          => $this->when($this->relationLoaded('branch') && $this->branch, [
                'id'   => $this->branch?->id,
                'name' => $this->branch?->translate('name'),
            ]),
            'space'           => $this->when($this->relationLoaded('space') && $this->space, [
                'id'   => $this->space?->id,
                'name' => $this->space?->translate('name'),
            ]),
            'resolution_note' => $this->when(
                $this->resolution_note !== null,
                fn () => $this->translate('resolution_note'),
            ),
            'values'          => $this->when($this->relationLoaded('values'), function () {
                return $this->values->map(fn ($v) => [
                    'field_key' => $v->field?->key,
                    'value'     => $v->resolvedValue(),
                ]);
            }),
            'submitted_at'    => $this->submitted_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }
}
