<?php

namespace Add\Hr\Time\Http\Resources;

use Add\Hr\Time\Models\TimePeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TimePeriod
 */
class TimePeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'year' => $this->year,
            'month' => $this->month,
            'status' => $this->status->value,
            'locked_at' => $this->locked_at?->toIso8601String(),
            'locked_by' => $this->locked_by,
        ];
    }
}
