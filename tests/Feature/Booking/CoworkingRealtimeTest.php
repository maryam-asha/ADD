<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\ArrivalRequestPurpose;
use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Events\ArrivalRequestCreated;
use App\Domain\Booking\Events\ArrivalRequestResolved;
use App\Domain\Booking\Events\CoworkingSessionEnded;
use App\Domain\Booking\Events\CoworkingStartConfirmed;
use App\Domain\Booking\Events\CoworkingStartExpired;
use App\Domain\Booking\Events\CoworkingStartRejected;
use App\Domain\Booking\Events\SessionEndRequestCancelled;
use App\Domain\Booking\Events\SessionEndRequestCreated;
use App\Domain\Booking\Events\SessionEndRequestResolved;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Booking\Models\SessionEndRequest;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

/**
 * The real-time nudges around a coworking session (config/broadcasting.php,
 * App\Domain\Booking\Events): which action sends which event, on which
 * channel, with nothing but ids and a status — and that a socket server
 * that fails never takes the request down with it.
 */
class CoworkingRealtimeTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private const EVENTS = [
        ArrivalRequestCreated::class,
        ArrivalRequestResolved::class,
        CoworkingStartConfirmed::class,
        CoworkingStartRejected::class,
        CoworkingStartExpired::class,
        CoworkingSessionEnded::class,
        SessionEndRequestCreated::class,
        SessionEndRequestCancelled::class,
        SessionEndRequestResolved::class,
    ];

    private Space $space;

    private Kiosk $kiosk;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
        $this->space = $this->coworkingSpace();
        [$this->kiosk, $this->secret] = $this->pairedKiosk($this->space);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function scan(): TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/scan', ['qr_value' => $this->qrFor($this->kiosk, $this->secret)]);
    }

    /** A member the plan covers, who has scanned in and waits for the desk. */
    private function memberWaiting(): User
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $this->scan()->assertCreated();

        return $member;
    }

    /** A member on a walk-in since 08:00, acting as that member. */
    private function memberInSession(): array
    {
        $member = $this->actingAsMember();
        $session = WalkinSession::factory()->create([
            'space_id' => $this->space->id,
            'user_id' => $member->id,
            'checked_in_at' => Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->utc(),
        ]);

        return [$member, $session];
    }

    private function requestEnd(): TestResponse
    {
        $token = $this->getJson('/api/v1/member/coworking-sessions/current/checkout')->json('data.checkout_token');

        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/coworking-sessions/current/end-request', [
            'checkout_token' => $token,
            'payment_method' => 'cash',
        ]);
    }

    private function onChannel(object $event, string $channel): bool
    {
        return array_map(fn (PrivateChannel $c) => $c->name, $event->broadcastOn()) === [$channel];
    }

    public function test_a_coworking_scan_tells_the_desk(): void
    {
        Event::fake(self::EVENTS);
        $this->memberWaiting();
        $request = ArrivalRequest::sole();

        Event::assertDispatchedTimes(ArrivalRequestCreated::class, 1);
        Event::assertDispatched(ArrivalRequestCreated::class, fn (ArrivalRequestCreated $e) => $this->onChannel($e, 'private-reception')
            && $e->broadcastAs() === 'arrival_request.created'
            && $e->broadcastWith() === ['arrival_request_id' => $request->id, 'purpose' => 'coworking_start', 'status' => 'pending']);
    }

    public function test_scanning_again_while_waiting_announces_nothing_new(): void
    {
        $this->memberWaiting();
        Event::fake(self::EVENTS);

        $this->scan()->assertOk();

        Event::assertNotDispatched(ArrivalRequestCreated::class);
    }

    public function test_a_room_arrival_tells_the_desk_too_and_its_confirmation_resolves_it(): void
    {
        Event::fake(self::EVENTS);
        $member = $this->actingAsMember();
        $room = Space::factory()->room()->create(['building_id' => $this->space->building_id]);
        Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => $room->id,
            'start_at' => now()->addMinutes(10),
            'end_at' => now()->addMinutes(70),
        ]);

        $this->scan()->assertCreated()->assertJsonPath('data.arrival_request.purpose', 'arrival');
        $request = ArrivalRequest::sole();
        Event::assertDispatched(ArrivalRequestCreated::class, fn ($e) => $e->broadcastWith()['purpose'] === 'arrival');

        $this->actingAsOperations();
        $this->postJson("/api/v1/admin/reception/arrival-requests/{$request->id}/confirm")->assertOk();

        Event::assertDispatched(ArrivalRequestResolved::class, fn (ArrivalRequestResolved $e) => $this->onChannel($e, 'private-reception')
            && $e->broadcastWith() === ['arrival_request_id' => $request->id, 'purpose' => 'arrival', 'status' => 'confirmed']);
        Event::assertNotDispatched(CoworkingStartConfirmed::class);
    }

    public function test_confirming_a_coworking_start_tells_the_member_and_the_desk(): void
    {
        $member = $this->memberWaiting();
        $request = ArrivalRequest::sole();
        Event::fake(self::EVENTS);

        $this->confirmAtReception($member)->assertOk();
        $session = WalkinSession::sole();

        Event::assertDispatched(CoworkingStartConfirmed::class, fn (CoworkingStartConfirmed $e) => $this->onChannel($e, "private-member.{$member->id}")
            && $e->broadcastAs() === 'coworking.start_confirmed'
            && $e->broadcastWith() === ['arrival_request_id' => $request->id, 'session_type' => 'walkin', 'session_id' => $session->id]);
        Event::assertDispatched(ArrivalRequestResolved::class, fn (ArrivalRequestResolved $e) => $e->broadcastAs() === 'arrival_request.resolved'
            && $e->broadcastWith() === ['arrival_request_id' => $request->id, 'purpose' => 'coworking_start', 'status' => 'confirmed']);
    }

    /** Sent after the commit, so a confirmation that is refused and rolled back announces nothing. */
    public function test_a_refused_confirmation_announces_nothing(): void
    {
        $member = $this->memberWaiting();
        $this->space->forceFill(['status' => OperationalStatus::Maintenance])->save();
        Event::fake(self::EVENTS);

        $this->confirmAtReception($member)->assertStatus(422);

        Event::assertNotDispatched(CoworkingStartConfirmed::class);
        Event::assertNotDispatched(ArrivalRequestResolved::class);
    }

    public function test_rejecting_a_coworking_start_tells_the_member_and_the_desk(): void
    {
        $member = $this->memberWaiting();
        $request = ArrivalRequest::sole();
        Event::fake(self::EVENTS);

        $this->actingAsOperations();
        $this->postJson("/api/v1/admin/reception/arrival-requests/{$request->id}/reject")->assertOk();

        Event::assertDispatched(CoworkingStartRejected::class, fn (CoworkingStartRejected $e) => $this->onChannel($e, "private-member.{$member->id}")
            && $e->broadcastAs() === 'coworking.start_rejected'
            && $e->broadcastWith() === ['arrival_request_id' => $request->id]);
        Event::assertDispatched(ArrivalRequestResolved::class, fn ($e) => $e->broadcastWith()['status'] === 'rejected');
    }

    public function test_rejecting_a_room_arrival_does_not_message_the_member(): void
    {
        $request = ArrivalRequest::factory()->create(['purpose' => ArrivalRequestPurpose::Arrival]);
        Event::fake(self::EVENTS);

        $this->actingAsOperations();
        $this->postJson("/api/v1/admin/reception/arrival-requests/{$request->id}/reject")->assertOk();

        Event::assertNotDispatched(CoworkingStartRejected::class);
        Event::assertDispatched(ArrivalRequestResolved::class, fn ($e) => $e->broadcastWith()['purpose'] === 'arrival');
    }

    public function test_the_expiry_sweep_tells_a_waiting_member_and_the_desk(): void
    {
        $member = $this->memberWaiting();
        $coworking = ArrivalRequest::sole();
        $room = ArrivalRequest::factory()->create(['purpose' => ArrivalRequestPurpose::Arrival, 'requested_at' => now()]);
        $this->travel(2)->hours();
        $fresh = ArrivalRequest::factory()->create(['purpose' => ArrivalRequestPurpose::Arrival, 'requested_at' => now()]);
        Event::fake(self::EVENTS);

        $this->artisan('kiosk:expire-stale-arrival-requests')->assertExitCode(0);

        $this->assertSame(ArrivalRequestStatus::Expired, $coworking->fresh()->status);
        $this->assertSame(ArrivalRequestStatus::Pending, $fresh->fresh()->status);
        Event::assertDispatchedTimes(CoworkingStartExpired::class, 1);
        Event::assertDispatched(CoworkingStartExpired::class, fn (CoworkingStartExpired $e) => $this->onChannel($e, "private-member.{$member->id}")
            && $e->broadcastAs() === 'coworking.start_expired'
            && $e->broadcastWith() === ['arrival_request_id' => $coworking->id]);
        Event::assertDispatchedTimes(ArrivalRequestResolved::class, 2);
        Event::assertDispatched(ArrivalRequestResolved::class, fn ($e) => $e->broadcastWith() === ['arrival_request_id' => $room->id, 'purpose' => 'arrival', 'status' => 'expired']);
    }

    public function test_asking_to_end_and_withdrawing_tell_the_desk(): void
    {
        [, $session] = $this->memberInSession();
        Event::fake(self::EVENTS);

        $this->requestEnd()->assertCreated();
        $endRequest = SessionEndRequest::sole();
        $expected = ['session_end_request_id' => $endRequest->id, 'session_type' => 'walkin', 'session_id' => $session->id];

        Event::assertDispatched(SessionEndRequestCreated::class, fn (SessionEndRequestCreated $e) => $this->onChannel($e, 'private-reception')
            && $e->broadcastAs() === 'session_end_request.created'
            && $e->broadcastWith() === $expected + ['status' => 'pending']);

        // Pressing again updates the waiting request — still the desk's cue.
        $this->requestEnd()->assertOk();
        Event::assertDispatchedTimes(SessionEndRequestCreated::class, 2);

        $this->deleteJson('/api/v1/member/coworking-sessions/current/end-request')->assertOk();
        Event::assertDispatched(SessionEndRequestCancelled::class, fn (SessionEndRequestCancelled $e) => $this->onChannel($e, 'private-reception')
            && $e->broadcastAs() === 'session_end_request.cancelled'
            && $e->broadcastWith() === $expected + ['status' => 'cancelled']);
    }

    public function test_confirming_an_end_tells_the_member_and_the_desk(): void
    {
        [$member, $session] = $this->memberInSession();
        $this->requestEnd()->assertCreated();
        $endRequest = SessionEndRequest::sole();
        Event::fake(self::EVENTS);

        $this->actingAsOperations();
        $this->postJson("/api/v1/admin/reception/session-end-requests/{$endRequest->id}/confirm")->assertOk();

        Event::assertDispatched(CoworkingSessionEnded::class, fn (CoworkingSessionEnded $e) => $this->onChannel($e, "private-member.{$member->id}")
            && $e->broadcastAs() === 'coworking.session_ended'
            && $e->broadcastWith() === ['session_end_request_id' => $endRequest->id, 'session_type' => 'walkin', 'session_id' => $session->id]);
        Event::assertDispatched(SessionEndRequestResolved::class, fn (SessionEndRequestResolved $e) => $this->onChannel($e, 'private-reception')
            && $e->broadcastAs() === 'session_end_request.resolved'
            && $e->broadcastWith() === ['session_end_request_id' => $endRequest->id, 'session_type' => 'walkin', 'session_id' => $session->id, 'status' => 'confirmed']);
    }

    /** The data is saved before the socket server is called; its failure is logged and the answer stands. */
    public function test_a_failing_socket_server_never_fails_the_request(): void
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

        $member = $this->memberWaiting();
        $this->assertSame(1, ArrivalRequest::count());

        $this->confirmAtReception($member)->assertOk();
        $this->assertSame(1, WalkinSession::count());

        Log::shouldHaveReceived('warning')->with('[Realtime] broadcast failed', \Mockery::on(fn ($context) => $context['error'] === 'Pusher is down'))->atLeast()->times(3);
    }
}
