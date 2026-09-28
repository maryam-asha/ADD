<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Booking\Enums\SlotState;
use App\Domain\Booking\Services\AvailabilityBrowseService;
use App\Domain\Foundation\Models\Space;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\Booking\AvailabilityCalendarRequest;
use App\Http\Requests\Member\Booking\AvailabilityDayRequest;
use Illuminate\Http\JsonResponse;

/**
 * Read-only. Nothing here holds a slot: a member who lingers on the
 * confirmation screen can still lose one, which is why the write path
 * re-checks under a row lock rather than trusting anything returned here.
 *
 * The locale comes from app()->getLocale(), which SetLocaleFromHeader has
 * already resolved from the `lang` header.
 */
class SpaceAvailabilityController extends Controller
{
    /**
     * What a member slot carries, in this order. No `reason`: the app greys a
     * chip by its `state`, and a refusal the member still
     * runs into is explained by the POST's own 422 message. The admin grid
     * keeps the reason for the desk.
     */
    private const SLOT_FIELDS = ['start_time', 'end_time', 'label', 'state', 'is_available'];

    public function month(
        AvailabilityCalendarRequest $request,
        Space $space,
        AvailabilityBrowseService $availability,
    ): JsonResponse {
        return response()->json($availability->calendar(
            $space,
            $request->validated('from'),
            $request->validated('to'),
            app()->getLocale(),
        ));
    }

    /**
     * Only what changes with the date, in one object:
     * `{data: {date, state, closed_banner, slots, counts, meta}}`.
     *
     * The slots are the space's own cells (`meta.granularity_minutes` each).
     * There is no duration: the member picks a start cell and an end cell,
     * at least the rules' `min_duration_minutes` long with no ceiling, and
     * the price is the space's
     * `hourly_rate_points` from the details times that length. The POST
     * re-checks the whole window, so a run of cells that cannot be booked
     * together is refused there with its reason.
     *
     * The booking setup — rules and closures from the space details
     * (`data.booking`, from the same builders), the branch's week from the
     * spaces list — is not repeated on every call: the member app reads it
     * once and re-asks this endpoint only
     * as the date changes. The admin route keeps the full payload
     * because the dashboard reads the rules from it;
     * SpaceAvailabilityControllerTest asserts the two agree on the rest.
     */
    public function day(
        AvailabilityDayRequest $request,
        Space $space,
        AvailabilityBrowseService $availability,
    ): JsonResponse {
        $day = $availability->day(
            $space,
            $request->validated('date'),
            app()->getLocale(),
            withSetup: false,
        );

        // Renamed in place so every other key keeps its position.
        $data = [];

        foreach ($day as $key => $value) {
            $data[$key === 'data' ? 'slots' : $key] = $value;
        }

        // Just the date, echoed so the app can drop a stale answer when dates
        // are tapped faster than they return. The rest of the day block was
        // either the app's to format (day, weekday, month, formatted date —
        // and English-only besides) or already here (`state`, `counts`).
        $data['date'] = $day['date']['date'];

        // Each slot keeps only what is its own. The instants (`start_at` /
        // `end_at`) are `date` + the two times; the length is
        // `meta.duration_minutes`; the price is `meta.price` — the same on
        // every slot at a flat rate, so repeating it said nothing more.
        //
        // And its state is one of the two a member sees — available or booked
        // (SlotState::forMember()). A slot whose time to be booked has gone —
        // already started, or inside the minimum notice — is not sent at all:
        // on today at 14:00 the list starts at the first start still bookable.
        $data['slots'] = array_values(array_filter(array_map(
            fn (array $slot) => array_combine(
                self::SLOT_FIELDS,
                array_map(fn (string $field) => $field === 'state'
                    ? SlotState::from($slot['state'])->forMember()->value
                    : $slot[$field], self::SLOT_FIELDS),
            ),
            $data['slots'],
        ), fn (array $slot) => $slot['state'] !== SlotState::Past->value));

        $data['counts'] = $this->memberCounts($data['slots']);

        // With no duration there is no length and no price to state.
        $data['meta'] = array_diff_key($data['meta'], ['duration_minutes' => true, 'price' => true]);

        return response()->json(['data' => $data]);
    }

    /**
     * `{available, booked, total}` over the slots the member is sent.
     *
     * @param  list<array<string, mixed>>  $slots
     * @return array<string, int>
     */
    private function memberCounts(array $slots): array
    {
        $counts = array_fill_keys(array_column(SlotState::memberStates(), 'value'), 0);

        foreach ($slots as $slot) {
            $counts[$slot['state']]++;
        }

        return [...$counts, 'total' => count($slots)];
    }
}
