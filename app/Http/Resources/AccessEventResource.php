<?php

namespace App\Http\Resources;

use App\Domain\Access\Models\AccessEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One door event for the dashboard's access log: what happened, through
 * which channel, at which lock and by whom — and the code it went through,
 * by id and source only.
 *
 * @mixin AccessEvent
 */
class AccessEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $device = $this->device;
        $space = $device?->space;
        $grant = $this->grant;

        return [
            'id' => $this->id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'event_type' => $this->event_type?->value,
            'channel' => $this->channel?->value,
            'device' => $device === null ? null : [
                'id' => $device->id,
                'external_ref' => $device->external_ref,
                'space' => $space === null ? null : ['id' => $space->id, 'name' => $space->displayTitle()],
            ],
            'actor' => $this->actor === null ? null : ['id' => $this->actor->id, 'name' => $this->actor->name],
            'grant' => $grant === null ? null : [
                'id' => $grant->id,
                'source_type' => $grant->source_type?->value,
                'booking_id' => $grant->source_type?->value === 'booking' ? $grant->source_id : null,
                'office_lease_id' => $grant->office_lease_id,
            ],
        ];
    }
}
