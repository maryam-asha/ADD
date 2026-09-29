<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\Enums\SlotState;
use PHPUnit\Framework\TestCase;

/**
 * The member app is sent two slot states, available and booked; a slot that
 * folds into past is left out of its list altogether. The desk keeps all
 * eight, because "every seat is taken" and "one booking holds
 * the room" are different facts to an operator — to a member both are simply
 * taken.
 */
class SlotStateMemberViewTest extends TestCase
{
    public function test_every_state_folds_into_available_booked_or_past(): void
    {
        $expected = [
            'available' => SlotState::Available,
            // The time to book it has gone: already started, or inside the
            // minimum notice. Neither comes back by waiting.
            'past' => SlotState::Past,
            'too_soon' => SlotState::Past,
            // Cannot be booked, whatever the cause.
            'booked' => SlotState::Booked,
            'at_capacity' => SlotState::Booked,
            'buffer' => SlotState::Booked,
            'outside_hours' => SlotState::Booked,
            'too_far_ahead' => SlotState::Booked,
        ];

        // Every case is listed, so a state added later fails here until it
        // is given a member meaning.
        $this->assertEqualsCanonicalizing(
            array_column(SlotState::cases(), 'value'),
            array_keys($expected),
        );

        foreach ($expected as $state => $memberState) {
            $this->assertSame($memberState, SlotState::from($state)->forMember(), $state);
        }
    }

    /** Exactly the states neither the member app nor the desk is sent. */
    public function test_only_past_and_too_soon_are_gone(): void
    {
        $gone = array_values(array_filter(SlotState::cases(), fn (SlotState $state) => $state->isGone()));

        $this->assertSame([SlotState::Past, SlotState::TooSoon], $gone);
    }

    public function test_a_member_is_sent_only_available_and_booked(): void
    {
        $this->assertSame(
            [SlotState::Available, SlotState::Booked],
            SlotState::memberStates(),
        );
    }
}
