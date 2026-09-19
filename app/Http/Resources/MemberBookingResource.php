<?php

namespace App\Http\Resources;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingPresentationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card in the member's bookings list.
 *
 * Deliberately lighter than the detail resource: no breakdown, no
 * stepper, no note. The list renders dozens of these, and building a
 * price breakdown per row would mean a wallet lookup per row.
 *
 * @property Booking $resource
 */
class MemberBookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();
        $presentation = app(BookingPresentationService::class);
        $space = $this->resource->space;

        return [
            'id' => $this->resource->id,
            'space' => [
                'id' => $space->id,
                'title' => $space->translate('name', $locale),
                'space_type' => $space->space_type->value,
                'main_image' => $space->main_image,
                'location' => $this->location($locale),
            ],
            'when' => $presentation->when($this->resource->start_at, $this->resource->end_at),
            'status' => $this->resource->status->value,
            'status_label' => __('api.booking.statuses.'.$this->resource->status->value, [], $locale),
            'payment' => $presentation->payment($this->resource, $locale),
            'is_upcoming' => $this->resource->end_at->isFuture(),
            // Both come from the same predicates the write endpoints
            // enforce, so a button shown here always works.
            'can_cancel' => $presentation->actions($this->resource, $locale)['can_cancel'],
        ];
    }

    private function location(string $locale): string
    {
        $branch = $this->resource->space->building?->branch?->translate('name', $locale);
        $floor = $this->resource->space->zone?->floor?->label;

        return collect([$branch, $floor])->filter()->implode(' – ');
    }
}
