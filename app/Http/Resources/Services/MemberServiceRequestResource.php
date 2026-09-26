<?php

namespace App\Http\Resources\Services;

use App\Domain\Services\Models\ServiceRequest;
use App\Support\Time\LocalTime;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member's own service request, in both their history list and the detail
 * screen — one shape, so the app renders a row and its detail from the same
 * model. The detail adds `values` (the submitted form answers), loaded only
 * there. The admin dashboard keeps ServiceRequestResource, which also carries
 * the full service type and location.
 *
 * @mixin ServiceRequest
 */
class MemberServiceRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'name' => $this->serviceType?->translate('name'),
            'description' => $this->serviceType?->translate('description'),
            // The section the service is listed under (e.g. "ADD Core").
            'service_type' => $this->serviceType?->axis?->translate('name'),
            'status' => $this->status->value,
            'resolution_note' => $this->when(
                $this->resolution_note !== null,
                fn () => $this->translate('resolution_note'),
            ),
            'submitted_at' => $this->moment($this->submitted_at),
            'processed_at' => $this->moment($this->processed_at),
            'values' => $this->whenLoaded('values', fn () => $this->values->map(fn ($v) => [
                'field_key' => $v->field?->key,
                'label' => $v->field?->fieldLabel(),
                'value' => $v->resolvedValue(),
            ])->values()),
        ];
    }

    /**
     * The instant in the branch's local time, as the date/time labels the
     * other member screens already use ("Sep 24, 2026", "10:12 AM"). Null
     * while it hasn't happened yet.
     *
     * @return array{date: string, time: string, formatted: string}|null
     */
    private function moment(?CarbonInterface $instant): ?array
    {
        $local = app(LocalTime::class)->toLocal($instant);

        if ($local === null) {
            return null;
        }

        return [
            'date' => $local->format('M d, Y'),
            'time' => $local->format('h:i A'),
            'formatted' => $local->format('M d, Y · h:i A'),
        ];
    }
}
