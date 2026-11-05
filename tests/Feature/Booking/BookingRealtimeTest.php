<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Events\BookingCancelled;
use App\Domain\Booking\Events\BookingCheckedIn;
use App\Domain\Booking\Events\BookingCheckedOut;
use App\Domain\Booking\Events\BookingCreated;
use App\Domain\Booking\Events\BookingEnded;
use App\Domain\Booking\Events\BookingExtended;
use App\Domain\Booking\Events\BookingPaid;
use App\Domain\Booking\Events\BookingPendingApproval;
use App\Domain\Booking\Events\BookingStarted;
use App\Domain\Booking\Events\CoworkingSessionPaid;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingApprovalService;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Booking\Services\BookingCheckInService;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Booking\Services\BookingExtensionService;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The real-time nudges around a room, office or hall booking: its start
 * (reception or the door, no acceptance), its extension, its end and its
 * payment reach the member's card and the desk — with nothing but ids and a
 * status — while a coworking booking keeps its own events.
 */
class BookingRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private const EVENTS = [
        BookingStarted::class,
        BookingCheckedIn::class,
        BookingExtended::class,
        BookingEnded::class,
        BookingCheckedOut::class,
        BookingPaid::class,
        CoworkingSessionPaid::class,
        BookingCreated::class,
        BookingPendingApproval::class,
        BookingCancelled::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, SettingSeeder::class]);
        // A Monday, inside the branch's hours below.
        Carbon::setTestNow($this->at('10:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse("2026-08-17 {$time}:00", 'Asia/Damascus')->utc();
    }

    private function member(int $points = 0): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        return $member;
    }

    /** 10:00–12:00 at 100 points an hour, unpaid. */
    private function booking(User $member, SpaceType $type = SpaceType::Room, array $attributes = []): Booking
    {
        $space = Space::factory()->create(['space_type' => $type, 'hourly_rate_points' => 100, 'slot_granularity_minutes' => 60]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => $this->at('10:00'),
            'end_at' => $this->at('12:00'),
            'price_points' => 200,
            'payment_state' => PaymentState::Unpaid,
            ...$attributes,
        ]);
    }

    private function onChannel(object $event, string $channel): bool
    {
        return array_map(fn (PrivateChannel $c) => $c->name, $event->broadcastOn()) === [$channel];
    }

    public function test_opening_the_room_door_tells_the_member_and_the_desk(): void
    {
        $member = $this->member();
        $booking = $this->booking($member);
        Event::fake(self::EVENTS);

        app(BookingCheckInService::class)->checkIn($booking, SessionStartSource::Door);

        Event::assertDispatchedTimes(BookingStarted::class, 1);
        Event::assertDispatched(BookingStarted::class, fn (BookingStarted $e) => $this->onChannel($e, "private-member.{$member->id}")
            && $e->broadcastAs() === 'booking.started'
            && $e->broadcastWith() === ['booking_id' => $booking->id, 'checked_in_via' => 'door']);
        Event::assertDispatched(BookingCheckedIn::class, fn (BookingCheckedIn $e) => $this->onChannel($e, 'private-reception')
            && $e->broadcastAs() === 'booking.checked_in'
            && $e->broadcastWith() === ['booking_id' => $booking->id, 'member_id' => $member->id]);
    }

    /** A coworking booking's start is reception's confirmation, announced by its own events. */
    public function test_checking_in_a_coworking_booking_sends_neither(): void
    {
        $booking = $this->booking($this->member(), SpaceType::CoSpace);
        Event::fake(self::EVENTS);

        app(BookingCheckInService::class)->checkIn($booking);

        $this->assertNotNull($booking->fresh()->checked_in_at);
        Event::assertNotDispatched(BookingStarted::class);
        Event::assertNotDispatched(BookingCheckedIn::class);
    }

    public function test_extending_tells_the_member_its_new_end(): void
    {
        $member = $this->member();
        $booking = $this->booking($member, attributes: ['checked_in_at' => $this->at('10:00')]);
        Carbon::setTestNow($this->at('11:30'));
        Event::fake(self::EVENTS);

        app(BookingExtensionService::class)->extend($booking, 60);

        Event::assertDispatched(BookingExtended::class, fn (BookingExtended $e) => $this->onChannel($e, "private-member.{$member->id}")
            && $e->broadcastAs() === 'booking.extended'
            && $e->broadcastWith() === ['booking_id' => $booking->id, 'end_at' => '2026-08-17T13:00:00+03:00']);
    }

    /** The wallet paid what was owed on close: the end says `paid`, and the payment is a booking's, not a session's. */
    public function test_closing_a_room_tells_the_member_and_the_desk_once_it_is_settled(): void
    {
        $member = $this->member(500);
        $booking = $this->booking($member, attributes: [
            'checked_in_at' => $this->at('10:00'),
            'chosen_payment_method' => 'wallet',
        ]);
        Event::fake(self::EVENTS);

        app(SessionClosureService::class)->closeOut($booking, $this->at('11:00'));

        Event::assertDispatched(BookingEnded::class, fn (BookingEnded $e) => $this->onChannel($e, "private-member.{$member->id}")
            && $e->broadcastAs() === 'booking.ended'
            && $e->broadcastWith() === ['booking_id' => $booking->id, 'payment_state' => 'paid']);
        Event::assertDispatched(BookingCheckedOut::class, fn (BookingCheckedOut $e) => $this->onChannel($e, 'private-reception')
            && $e->broadcastAs() === 'booking.checked_out'
            && $e->broadcastWith() === ['booking_id' => $booking->id, 'member_id' => $member->id, 'payment_state' => 'paid']);
        Event::assertDispatched(BookingPaid::class);
        Event::assertNotDispatched(CoworkingSessionPaid::class);
    }

    public function test_a_room_left_owing_ends_unpaid(): void
    {
        $member = $this->member();
        $booking = $this->booking($member, attributes: [
            'checked_in_at' => $this->at('10:00'),
            'chosen_payment_method' => 'cash',
        ]);
        Event::fake(self::EVENTS);

        app(SessionClosureService::class)->closeOut($booking, $this->at('11:00'));

        Event::assertDispatched(BookingEnded::class, fn (BookingEnded $e) => $e->broadcastWith()['payment_state'] === 'unpaid');
        Event::assertNotDispatched(BookingPaid::class);
    }

    public function test_the_desk_settling_a_closed_room_tells_the_member_it_is_paid(): void
    {
        $member = $this->member();
        $booking = $this->booking($member, attributes: [
            'checked_in_at' => $this->at('10:00'),
            'chosen_payment_method' => 'cash',
        ]);
        app(SessionClosureService::class)->closeOut($booking, $this->at('11:00'));
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Event::fake(self::EVENTS);

        app(SessionClosureService::class)->settlePayment($booking->fresh(), PaymentMethod::Cash, $operator);

        $this->assertSame(PaymentState::Paid, $booking->fresh()->payment_state);
        Event::assertDispatchedTimes(BookingPaid::class, 1);
        Event::assertDispatched(BookingPaid::class, fn (BookingPaid $e) => $this->onChannel($e, "private-member.{$member->id}")
            && $e->broadcastAs() === 'booking.paid'
            && $e->broadcastWith() === ['booking_id' => $booking->id]);
        Event::assertNotDispatched(CoworkingSessionPaid::class);
    }

    /** Paid when it is made: no card waits for that payment, so nothing is announced. */
    public function test_paying_a_room_before_it_starts_announces_nothing(): void
    {
        $member = $this->member(500);
        $booking = $this->booking($member);
        Event::fake(self::EVENTS);

        app(PaymentService::class)->start(PaymentPurpose::Booking, $member, 'wallet', [
            'payable' => $booking,
            'wallet_owner_type' => OwnerType::User,
            'wallet_owner_id' => $member->id,
        ]);

        $this->assertSame(PaymentState::Paid, $booking->fresh()->payment_state);
        $this->assertSame(PaymentStatus::Accepted, Payment::query()->forPayable($booking)->sole()->status);
        $this->assertSame(PaymentSource::Wallet, $booking->fresh()->payment_source);
        Event::assertNotDispatched(BookingPaid::class);
    }

    /** The check-in is saved before the socket server is called; its failure is logged and the check-in stands. */
    public function test_a_failing_socket_server_never_fails_the_check_in(): void
    {
        Broadcast::extend('exploding', fn () => new class extends Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new BroadcastException('Pusher is down');
            }
        });
        config(['broadcasting.connections.exploding' => ['driver' => 'exploding'], 'broadcasting.default' => 'exploding']);
        Log::spy();
        $booking = $this->booking($this->member());

        app(BookingCheckInService::class)->checkIn($booking, SessionStartSource::Door);

        $this->assertNotNull($booking->fresh()->checked_in_at);
        Log::shouldHaveReceived('warning')->with('[Realtime] broadcast failed', \Mockery::on(fn ($context) => $context['error'] === 'Pusher is down'))->twice();
    }

    public function test_confirmed_booking_creation_announces_booking_created_on_reception(): void
    {
        $member = $this->member(500);
        $space = Space::factory()->create([
            'space_type' => SpaceType::Room,
            'hourly_rate_points' => 100,
            'slot_granularity_minutes' => 60,
            'requires_approval' => false,
        ]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);
        Event::fake(self::EVENTS);

        $booking = app(BookingCreationService::class)->create(
            $space,
            $member,
            $this->at('14:00'),
            $this->at('16:00'),
            paymentMethod: 'cash',
        );

        Event::assertDispatchedTimes(BookingCreated::class, 1);
        Event::assertDispatched(BookingCreated::class, fn (BookingCreated $e) => $this->onChannel($e, 'private-reception')
            && $e->broadcastAs() === 'booking.created'
            && $e->broadcastWith() === [
                'booking_id' => $booking->id,
                'member_id' => $member->id,
                'space_id' => $space->id,
                'status' => 'confirmed',
            ]);
    }

    public function test_pending_booking_creation_announces_booking_pending_approval_on_reception(): void
    {
        $member = $this->member(500);
        $space = Space::factory()->create([
            'space_type' => SpaceType::Room,
            'hourly_rate_points' => 100,
            'slot_granularity_minutes' => 60,
            'requires_approval' => true,
        ]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);
        Event::fake(self::EVENTS);

        $booking = app(BookingCreationService::class)->create(
            $space,
            $member,
            $this->at('14:00'),
            $this->at('16:00'),
            paymentMethod: 'cash',
        );

        $this->assertSame(BookingStatus::Pending, $booking->status);
        Event::assertDispatchedTimes(BookingPendingApproval::class, 1);
        Event::assertDispatched(BookingPendingApproval::class, fn (BookingPendingApproval $e) => $this->onChannel($e, 'private-reception')
            && $e->broadcastAs() === 'booking.pending_approval'
            && $e->broadcastWith() === [
                'booking_id' => $booking->id,
                'member_id' => $member->id,
                'space_id' => $space->id,
                'status' => 'pending',
            ]);
    }

    public function test_approving_a_pending_booking_announces_booking_created_on_reception(): void
    {
        $member = $this->member();
        $booking = $this->booking($member, attributes: ['status' => BookingStatus::Pending]);
        $operator = User::factory()->create();
        Event::fake(self::EVENTS);

        app(BookingApprovalService::class)->approve($booking, $operator);

        Event::assertDispatchedTimes(BookingCreated::class, 1);
        Event::assertDispatched(BookingCreated::class, fn (BookingCreated $e) => $this->onChannel($e, 'private-reception')
            && $e->broadcastAs() === 'booking.created'
            && $e->broadcastWith() === [
                'booking_id' => $booking->id,
                'member_id' => $member->id,
                'space_id' => $booking->space_id,
                'status' => 'confirmed',
            ]);
    }

    public function test_rejecting_a_pending_booking_announces_booking_cancelled_on_reception(): void
    {
        $member = $this->member();
        $booking = $this->booking($member, attributes: ['status' => BookingStatus::Pending]);
        $operator = User::factory()->create();
        Event::fake(self::EVENTS);

        app(BookingApprovalService::class)->reject($booking, $operator, 'Room unavailable');

        Event::assertDispatchedTimes(BookingCancelled::class, 1);
        Event::assertDispatched(BookingCancelled::class, fn (BookingCancelled $e) => $this->onChannel($e, 'private-reception')
            && $e->broadcastAs() === 'booking.cancelled'
            && $e->broadcastWith() === [
                'booking_id' => $booking->id,
                'member_id' => $member->id,
                'space_id' => $booking->space_id,
                'status' => 'cancelled',
            ]);
    }

    public function test_cancelling_a_booking_announces_booking_cancelled_on_reception(): void
    {
        $member = $this->member();
        $booking = $this->booking($member, attributes: [
            'status' => BookingStatus::Confirmed,
            'start_at' => $this->at('14:00'),
            'end_at' => $this->at('16:00'),
        ]);
        Event::fake(self::EVENTS);

        app(BookingCancellationService::class)->cancel($booking);

        Event::assertDispatchedTimes(BookingCancelled::class, 1);
        Event::assertDispatched(BookingCancelled::class, fn (BookingCancelled $e) => $this->onChannel($e, 'private-reception')
            && $e->broadcastAs() === 'booking.cancelled'
            && $e->broadcastWith() === [
                'booking_id' => $booking->id,
                'member_id' => $member->id,
                'space_id' => $booking->space_id,
                'status' => 'cancelled',
            ]);
    }
}
