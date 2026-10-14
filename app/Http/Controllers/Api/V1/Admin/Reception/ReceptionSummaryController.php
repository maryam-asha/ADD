<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SessionEndRequest;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Payments\OutstandingItems;
use App\Domain\Settings\Services\SettingService;
use App\Http\Controllers\Controller;
use App\Support\Time\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * The reception desk's day at a glance: who is inside, who is expected, who
 * is waiting on the desk, and what is still owed — and what needs the desk
 * next, soonest first. Read-only; every count comes from the same rows the
 * screens it links to list.
 */
class ReceptionSummaryController extends Controller
{
    /** The most rows "up next" shows; the screens it links to have the rest. */
    private const UPCOMING_LIMIT = 15;

    public function show(OutstandingItems $outstanding, SettingService $settings, LocalTime $time): JsonResponse
    {
        $now = CarbonImmutable::now()->utc();
        $timezone = $settings->get('app.timezone', 'Asia/Damascus');
        $endOfToday = $now->setTimezone($timezone)->endOfDay()->utc();
        $locale = app()->getLocale();

        // Today's bookings not yet arrived.
        $expected = Booking::query()->expectedBy($now, $endOfToday);

        $endRequests = SessionEndRequest::query()
            ->where('status', SessionEndRequestStatus::Pending)
            ->with(['sessionable.space', 'user:id,name'])
            ->orderBy('requested_at')
            ->get();

        $arrivals = ArrivalRequest::query()
            ->where('status', ArrivalRequestStatus::Pending)
            ->with(['user:id,name', 'matchedBooking.space'])
            ->orderBy('requested_at')
            ->get();

        $upcoming = collect()
            ->concat($endRequests->map(fn (SessionEndRequest $request) => [
                'kind' => 'end_request',
                'id' => $request->id,
                'at' => $request->requested_at,
                'member' => ['id' => $request->user?->id, 'name' => $request->user?->name],
                'space' => $request->sessionable?->space?->translate('name', $locale),
                'session' => $request->sessionable === null ? null : [
                    'type' => $request->sessionable->coworkingType(),
                    'id' => $request->sessionable->id,
                ],
                'payment_method' => $request->payment_method,
            ]))
            ->concat($arrivals->map(fn (ArrivalRequest $request) => [
                'kind' => 'arrival',
                'id' => $request->id,
                'at' => $request->requested_at,
                'member' => ['id' => $request->user?->id, 'name' => $request->user?->name],
                'space' => $request->matchedBooking?->space?->translate('name', $locale),
                'session' => null,
                'payment_method' => null,
            ]))
            ->concat((clone $expected)->with(['space', 'user:id,name'])->orderBy('start_at')->limit(self::UPCOMING_LIMIT)->get()
                ->map(fn (Booking $booking) => [
                    'kind' => 'booking',
                    'id' => $booking->id,
                    'at' => $booking->start_at,
                    'member' => ['id' => $booking->user?->id, 'name' => $booking->user?->name],
                    'space' => $booking->space?->translate('name', $locale),
                    'session' => null,
                    'payment_method' => null,
                ]))
            ->sortBy(fn (array $row) => $row['at']->getTimestamp())
            ->take(self::UPCOMING_LIMIT)
            ->map(fn (array $row) => [...$row, 'at' => $time->toLocal($row['at'])->toIso8601String()])
            ->values();

        return response()->json(['data' => [
            'stats' => [
                'active_sessions' => Booking::query()->inProgress()->count() + WalkinSession::query()->open()->count(),
                'expected_bookings' => $expected->count(),
                'pending_arrivals' => $arrivals->count(),
                'pending_end_requests' => $endRequests->count(),
                'pending_payments' => $outstanding->countAll(),
            ],
            'upcoming' => $upcoming,
        ]]);
    }
}
