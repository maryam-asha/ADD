<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Services\EventBrowseService;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Finance\Payments\PayableCheckout;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Payments\PaymentStart;
use App\Domain\Membership\Enums\OwnerType;
use App\Http\Controllers\Concerns\AnswersPayments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Experience\RegisterForEventRequest;
use App\Http\Requests\Public\BrowseEventsRequest;
use App\Services\Experience\EventRegistrationService;
use App\Support\Enums\EventPaymentState;
use App\Support\Enums\EventRegistrationStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class EventController extends Controller
{
    use AnswersPayments;

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
        PayableCheckout $checkout,
        PaymentMethodRegistry $methods,
    ): JsonResponse {
        $event = Event::findOrFail($id);

        if ($request->validated('ticket_id') !== null) {
            return $this->payExisting($request, $event, $checkout);
        }

        $method = $request->validated('payment_method');

        $result = $registrationService->register(
            $event,
            $request->user(),
            $request->formFieldValues(),
            $method,
            $request->validated('wallet_owner_type') ? OwnerType::from($request->validated('wallet_owner_type')) : null,
            $request->validated('wallet_owner_id'),
            $request->validated('coupon_code'),
        );

        // A card pays once the seat is confirmed; a pending one is paid
        // after approval, from pay-now. If the gateway cannot be reached the
        // seat stands, unpaid.
        $attendee = EventAttendee::query()->find($result['ticket_id']);

        if ($method !== null && in_array($method, $methods->gatewayKeys(), true)
            && $attendee?->status === EventRegistrationStatus::Approved
            && $attendee->payment_state === EventPaymentState::Unpaid) {
            $result += $this->payBesideNewRecord($checkout, PaymentPurpose::EventRegistration, $attendee, $request->user(), $method);
        }

        return response()->json(['data' => $result], 201);
    }

    /**
     * The registration sent again with its ticket: nothing is registered
     * and no registration rule runs (window, capacity, form). An approved
     * seat is paid by the method sent; one still pending approval only has
     * its stored choice updated — nothing is charged until approval.
     */
    private function payExisting(RegisterForEventRequest $request, Event $event, PayableCheckout $checkout): JsonResponse
    {
        $member = $request->user();
        $attendee = EventAttendee::query()
            ->where('user_id', $member->id)
            ->where('event_id', $event->id)
            ->seatHolding()
            ->findOrFail($request->validated('ticket_id'));

        $method = $request->validated('payment_method') ?? WalletMethod::KEY;

        if ($attendee->status === EventRegistrationStatus::Pending) {
            $attendee->forceFill([
                'payment_method' => $method,
                'payment_wallet_owner_type' => $request->validated('wallet_owner_type'),
                'payment_wallet_owner_id' => $request->validated('wallet_owner_id'),
            ])->save();

            return response()->json(['data' => [
                'ticket_id' => $attendee->id,
                'status' => $attendee->status->value,
                'payment' => null,
                'redirect_url' => null,
                'next' => PaymentStart::PAY_AT_RECEPTION,
            ]]);
        }

        try {
            $outcome = $checkout->pay(PaymentPurpose::EventRegistration, $attendee, $member, $method, [
                'wallet_owner_type' => $request->validated('wallet_owner_type'),
                'wallet_owner_id' => $request->validated('wallet_owner_id'),
            ], $request->validated('coupon_code'));
        } catch (Throwable $e) {
            return $this->paymentFailureResponse($e, $member->id) ?? throw $e;
        }

        return response()->json(['data' => [
            'ticket_id' => $attendee->id,
            'status' => $attendee->refresh()->status->value,
        ] + $this->checkoutFields($outcome) + ($outcome->coupon !== null ? ['coupon' => $outcome->coupon] : [])]);
    }

    /**
     * The form a member fills in to register.
     *
     * Always 200s. An external event answers with its link alone; an event
     * that asks nothing carries no `registration_form` key at all. Identity
     * is never a question here: it comes from the account, and no form
     * field may redefine it.
     */
    public function registrationForm(Request $request, int|string $id, EventBrowseService $service): JsonResponse
    {
        $event = Event::with('registrationForm.activeFields')->findOrFail($id);

        return response()->json([
            'data' => $service->registrationFormPayload($event),
        ]);
    }
}
