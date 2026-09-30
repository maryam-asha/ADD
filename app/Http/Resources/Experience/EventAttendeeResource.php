<?php

namespace App\Http\Resources\Experience;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Http\Resources\Forms\FormAnswerResource;
use App\Support\Presenters\PriceLabelPresenter;
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
            'checked_in_at' => $this->checked_in_at?->toISOString(),
            'checked_in_by' => $this->checked_in_by,
            // Null for a free event: nothing to pay.
            'payment' => $this->payment_state === null ? null : [
                'state' => $this->payment_state->value,
                'method' => $this->payment_method,
                'price_points' => $this->price_points,
                'price_label' => app(PriceLabelPresenter::class)->total((int) $this->price_points, $this->user)['price'],
                'paid_at' => $this->paid_at?->toISOString(),
            ],
            'answers' => FormAnswerResource::collection($this->whenLoaded('formAnswers')),
        ];
    }
}
