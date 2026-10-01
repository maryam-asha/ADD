<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Services\EventBrowseService;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Http\Controllers\Concerns\RespondsToGatewayFailures;
use App\Http\Controllers\Controller;
use App\Http\Requests\Experience\RegisterForEventRequest;
use App\Http\Requests\Public\BrowseEventsRequest;
use App\Http\Resources\Finance\PaymentResource;
use App\Http\Resources\Forms\FormFieldResource;
use App\Services\Experience\EventRegistrationService;
use App\Support\Enums\EventPaymentState;
use App\Support\Enums\EventRegistrationStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class EventController extends Controller
{
    use RespondsToGatewayFailures;

    /**
     * Browse events for member app with custom filters (free, cancelled, category, search).
     */
    public function index(BrowseEventsRequest $request, EventBrowseService $service): JsonResponse
    {
        $user = $request->user();
        $result = $service->browse($request->validated(), $user);

        return response()->json($result);
    }

    /**
     * Retrieve event categories separately for the member app.
     */
    public function categories(EventBrowseService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->categories(),
        ]);
    }

    /**
     * Retrieve event statuses list for filtering in member app.
     */
    public function statuses(EventBrowseService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->statuses(),
        ]);
    }

    /**
     * Display details of a specific event for member app.
     */
    public function show(Request $request, int|string $id, EventBrowseService $service): JsonResponse
    {
        $user = $request->user();
        $details = $service->details($id, $user);

        return response()->json([
            'data' => $details,
        ]);
    }

    /**
     * Retrieve events that the authenticated member is registered for ("My Events").
     */
    public function myEvents(Request $request, EventBrowseService $service): JsonResponse
    {
        $user = $request->user();
        $result = $service->myEvents($user, $request->all());

        return response()->json($result);
    }

    /**
     * Register the authenticated member for an event.
     */
    public function register(
        RegisterForEventRequest $request,
        int|string $id,
        EventRegistrationService $registrationService,
        PaymentService $payments,
        PaymentMethodRegistry $methods,
    ): JsonResponse {
        $event = Event::findOrFail($id);
        $method = $request->validated('payment_method');

        $result = $registrationService->register(
            $event,
            $request->user(),
            $request->formFieldValues(),
            $method,
            $request->validated('wallet_owner_type') ? OwnerType::from($request->validated('wallet_owner_type')) : null,
            $request->validated('wallet_owner_id'),
        );

        // A card pays once the seat is confirmed; a pending one is paid
        // after approval, from pay-now. If the gateway cannot be reached the
        // seat stands, unpaid.
        $attendee = EventAttendee::query()->find($result['ticket_id']);

        if ($method !== null && in_array($method, $methods->gatewayKeys(), true)
            && $attendee?->status === EventRegistrationStatus::Approved
            && $attendee->payment_state === EventPaymentState::Unpaid) {
            $result += $this->startCardPayment($payments, $attendee, $request->user(), $method, $request->header('lang'));
        }

        return response()->json(['data' => $result], 201);
    }

    /**
     * @return array{payment: PaymentResource|null, redirect_url: string|null, payment_error?: string}
     */
    private function startCardPayment(PaymentService $payments, EventAttendee $attendee, User $member, string $method, ?string $lang): array
    {
        try {
            ['payment' => $payment, 'start' => $start] = $payments->start(PaymentPurpose::EventRegistration, $member, $method, array_filter([
                'payable' => $attendee,
                'lang' => in_array($lang, ['ar', 'en'], true) ? $lang : null,
            ]));
        } catch (ReceptionActionException $e) {
            // Nothing to open (nothing due): the seat stands, as for a
            // gateway that cannot be reached.
            return ['payment' => null, 'redirect_url' => null, 'payment_error' => __($e->messageKey, $e->params)];
        } catch (Throwable $e) {
            $failure = $this->gatewayFailure($e, $member->id) ?? throw $e;

            return ['payment' => null, 'redirect_url' => null, 'payment_error' => __($failure['key'])];
        }

        return ['payment' => new PaymentResource($payment), 'redirect_url' => $start->redirectUrl];
    }

    /**
     * The form a member fills in to register.
     *
     * Always 200s, with an empty `fields` list when the event asks nothing,
     * so the app has a single code path. Identity is returned separately
     * and read-only: it comes from the account, and no form field may
     * redefine it.
     */
    public function registrationForm(Request $request, int|string $id): JsonResponse
    {
        $event = Event::with('registrationForm.activeFields')->findOrFail($id);

        if ($event->registersExternally()) {
            return response()->json([
                'data' => [
                    'external_form_url' => $event->external_form_url,
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'external_form_url' => null,
                'registration_form' => FormFieldResource::collection($event->registrationForm?->activeFields ?? collect()),
            ],
        ]);
    }
}
