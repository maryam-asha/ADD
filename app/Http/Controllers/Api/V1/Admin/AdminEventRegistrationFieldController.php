<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Models\EventRegistrationField;
use App\Http\Controllers\Controller;
use App\Http\Requests\Experience\Admin\StoreEventRegistrationFieldRequest;
use App\Http\Requests\Experience\Admin\UpdateEventRegistrationFieldRequest;
use App\Http\Resources\Experience\EventRegistrationFieldResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The questions one event asks its registrants.
 *
 * `has_registration_form` on the event is kept in step here so the browse
 * surface can advertise a form without counting rows per card.
 */
class AdminEventRegistrationFieldController extends Controller
{
    public function index(Event $event): AnonymousResourceCollection
    {
        // Every field, active or not: this is the editor, not the form.
        return EventRegistrationFieldResource::collection($event->registrationFields);
    }

    public function store(StoreEventRegistrationFieldRequest $request, Event $event): JsonResponse
    {
        $field = $event->registrationFields()->create($request->validated());

        $this->syncFormFlag($event);

        return (new EventRegistrationFieldResource($field))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateEventRegistrationFieldRequest $request,
        Event $event,
        EventRegistrationField $field,
    ): JsonResponse {
        $this->assertBelongsToEvent($event, $field);

        $field->update($request->validated());

        $this->syncFormFlag($event);

        return response()->json(['message' => __('api.event.form_field_updated')]);
    }

    public function destroy(Event $event, EventRegistrationField $field): JsonResponse
    {
        $this->assertBelongsToEvent($event, $field);

        $field->delete();

        $this->syncFormFlag($event);

        return response()->json(['message' => __('api.event.form_field_deleted')]);
    }

    /**
     * An event has a form exactly while it has at least one active field.
     */
    private function syncFormFlag(Event $event): void
    {
        $event->forceFill([
            'has_registration_form' => $event->registrationFields()->where('is_active', true)->exists(),
        ])->save();
    }

    private function assertBelongsToEvent(Event $event, EventRegistrationField $field): void
    {
        abort_if($field->event_id !== $event->id, 404);
    }
}
