<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Http\Controllers\Controller;
use App\Http\Resources\Experience\EventAttendeeResource;
use App\Services\Experience\EventApprovalService;
use App\Services\Experience\Exceptions\EventActionException;
use App\Support\Enums\EventRegistrationStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The attendee roster for one event, and the approve/reject decisions on
 * it. Registrations can grow without bound, so listings paginate rather
 * than returning every row the way AdminResourceController does.
 */
class AdminEventAttendeeController extends Controller
{
    use LogsSensitiveActions;

    public function __construct(
        private readonly EventApprovalService $approvals,
    ) {}

    public function index(Request $request, Event $event): AnonymousResourceCollection
    {
        $query = $event->attendees()
            ->with(['values.field'])
            ->latest('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return EventAttendeeResource::collection(
            $query->paginate((int) $request->query('per_page', 20))
        );
    }

    public function pending(Request $request, Event $event): AnonymousResourceCollection
    {
        return EventAttendeeResource::collection(
            $event->attendees()
                ->where('status', EventRegistrationStatus::Pending)
                ->with(['values.field'])
                ->oldest('id')
                ->paginate((int) $request->query('per_page', 20))
        );
    }

    public function approve(Request $request, Event $event, EventAttendee $attendee): JsonResponse
    {
        $this->assertBelongsToEvent($event, $attendee);

        try {
            $this->approvals->approve($attendee, $request->user());
        } catch (EventActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('event_registration_approved', $attendee);

        return response()->json(['message' => __('api.event.registration_approved')]);
    }

    public function reject(Request $request, Event $event, EventAttendee $attendee): JsonResponse
    {
        $this->assertBelongsToEvent($event, $attendee);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->approvals->reject($attendee, $request->user(), $validated['rejection_reason']);
        } catch (EventActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('event_registration_rejected', $attendee);

        return response()->json(['message' => __('api.event.registration_rejected')]);
    }

    /**
     * Turn down everything still awaiting a decision on this event.
     */
    public function rejectPending(Request $request, Event $event): JsonResponse
    {
        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $count = $this->approvals->rejectAllPending(
                $event,
                $request->user(),
                $validated['rejection_reason'],
            );
        } catch (EventActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('event_registrations_bulk_rejected', $event, ['count' => $count]);

        return response()->json([
            'message' => __('api.event.pending_rejected', ['count' => $count]),
            'rejected_count' => $count,
        ]);
    }

    /**
     * Both models are bound independently, so nothing otherwise stops an
     * attendee id from one event being acted on through another's URL.
     */
    private function assertBelongsToEvent(Event $event, EventAttendee $attendee): void
    {
        abort_if($attendee->event_id !== $event->id, 404);
    }
}
