<?php

namespace Add\Hr\Payroll\Http\Resources;

use Add\Hr\Payroll\Models\RateTable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RateTable
 */
class RateTableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'versions' => $this->whenLoaded('versions', fn () => $this->versions->map(fn ($version) => [
                'id' => $version->id,
                'effective_from' => $version->effective_from->toDateString(),
                'effective_to' => $version->effective_to?->toDateString(),
                'rate' => $version->rate,
                'max_base' => $version->max_base,
                'brackets' => $version->brackets,
                'currency' => $version->currency,
                'note' => $version->note,
            ])->values()),
        ];
    }
}
