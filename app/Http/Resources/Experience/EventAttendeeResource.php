<?php

namespace App\Http\Resources\Experience;

use App\Domain\Ecosystem\Models\EventAttendee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A registration as operations sees it: who applied, where it stands, and
 * what they answered.
 *
 * @mixin EventAttendee
 */
class EventAttendeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'attended' => $this->attended,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toISOString(),
            'registered_at' => $this->created_at?->toISOString(),
            'answers' => $this->whenLoaded('values', fn () => $this->values->map(fn ($value) => [
                'field_key' => $value->field?->key,
                'label' => $value->field?->translate('label'),
                'value' => $value->resolvedValue(),
            ])->values()),
        ];
    }
}
