<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessHourExceptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'date' => $this->date->toDateString(),
            'is_closed' => $this->is_closed,
            'open_time' => $this->open_time,
            'close_time' => $this->close_time,
            'reason' => $this->reason,
        ];
    }
}
