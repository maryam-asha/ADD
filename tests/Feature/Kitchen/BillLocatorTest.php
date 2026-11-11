<?php

namespace Tests\Feature\Kitchen;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\QrPointPlace;
use App\Domain\Kitchen\Exceptions\KitchenOrderException;
use App\Domain\Kitchen\Models\QrPoint;
use App\Domain\Kitchen\Services\BillLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which bill an order joins (spec "Which bill an order joins"): the room a
 * QR names, else the coworking session, else the one checked-in booking,
 * else the member's choice.
 */
class BillLocatorTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Building $building;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = User::factory()->create();
        $this->building = Building::factory()->create(['branch_id' => Branch::factory()->create()->id]);
    }

    private function walkin(): WalkinSession
    {
        $cospace = Space::factory()->create(['building_id' => $this->building->id]);

        return WalkinSession::factory()->create(['user_id' => $this->member->id, 'space_id' => $cospace->id]);
    }

    private function roomBooking(): Booking
    {
        $room = Space::factory()->room()->create(['building_id' => $this->building->id]);

        return Booking::factory()->checkedIn()->create([
            'user_id' => $this->member->id,
            'space_id' => $room->id,
            'start_at' => now()->subMinutes(10),
            'end_at' => now()->addHour(),
        ]);
    }

    private function qr(QrPointPlace $type, int $placeId): QrPoint
    {
        return QrPoint::factory()->create([
            'branch_id' => $this->building->branch_id,
            'place_type' => $type,
            'place_id' => $placeId,
        ]);
    }

    private function resolve(?QrPoint $qr = null, ?array $chosen = null): Booking|WalkinSession
    {
        return app(BillLocator::class)->resolve($this->member, $qr, $chosen);
    }

    public function test_bill_precedence(): void
    {
        $walkin = $this->walkin();
        $booking = $this->roomBooking();
        $desk = SeatDesk::factory()->create(['space_id' => $walkin->space_id]);

        $roomBill = $this->resolve($this->qr(QrPointPlace::Space, $booking->space_id));
        $deskBill = $this->resolve($this->qr(QrPointPlace::SeatDesk, $desk->id));
        $noQrBill = $this->resolve();

        $this->assertInstanceOf(Booking::class, $roomBill);
        $this->assertSame($booking->id, $roomBill->id);
        $this->assertInstanceOf(WalkinSession::class, $deskBill);
        $this->assertSame($walkin->id, $deskBill->id);
        $this->assertInstanceOf(WalkinSession::class, $noQrBill);
        $this->assertSame($walkin->id, $noQrBill->id);
    }

    public function test_a_chosen_bill_wins_over_the_open_session(): void
    {
        $this->walkin();
        $booking = $this->roomBooking();

        $bill = $this->resolve(null, ['type' => 'booking', 'id' => $booking->id]);

        $this->assertInstanceOf(Booking::class, $bill);
        $this->assertSame($booking->id, $bill->id);
    }

    public function test_single_room_booking_is_used(): void
    {
        $booking = $this->roomBooking();

        $bill = $this->resolve();

        $this->assertInstanceOf(Booking::class, $bill);
        $this->assertSame($booking->id, $bill->id);
    }

    public function test_two_room_bookings_need_a_choice(): void
    {
        $this->roomBooking();
        $second = $this->roomBooking();

        try {
            $this->resolve();
            $this->fail('Picked a bill among two checked-in bookings without a choice');
        } catch (KitchenOrderException $e) {
            $this->assertSame('api.kitchen.choose_bill', $e->messageKey);
            $this->assertSame(422, $e->status);
        }

        $chosen = $this->resolve(null, ['type' => 'booking', 'id' => $second->id]);
        $this->assertSame($second->id, $chosen->id);

        $this->expectException(KitchenOrderException::class);
        $this->resolve(null, ['type' => 'walkin', 'id' => $second->id]);
    }

    public function test_no_session_or_booking_is_refused(): void
    {
        // A booking not yet checked in is not a bill.
        Booking::factory()->create(['user_id' => $this->member->id, 'start_at' => now()->subMinutes(5), 'end_at' => now()->addHour()]);

        try {
            $this->resolve();
            $this->fail('Found a bill for a member with no open session or booking');
        } catch (KitchenOrderException $e) {
            $this->assertSame('api.kitchen.no_open_session', $e->messageKey);
            $this->assertSame(403, $e->status);
        }

        $this->assertSame([], app(BillLocator::class)->eligibleBills($this->member));
    }
}
