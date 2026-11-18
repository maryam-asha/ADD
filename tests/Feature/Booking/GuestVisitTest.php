<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\GuestVisitStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\GuestVisit;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\GuestVisitService;
use App\Domain\Booking\Services\OpenCoworkingSessionLocator;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\PlanHourUsage;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

/**
 * Coworking guests (GuestVisitService): free for the grace, then a walk-in
 * billed from the arrival — on the guest's account when they have one, on
 * the host's otherwise, with none of the host's plan.
 */
class GuestVisitTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private Space $space;

    private User $host;

    private WalkinSession $hostSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());

        $this->space = $this->coworkingSpace(10, 100);
        $this->host = User::factory()->create(['phone' => '+963911000001']);
        $this->host->assignRole('member');

        $this->hostSession = WalkinSession::factory()->create([
            'space_id' => $this->space->id,
            'user_id' => $this->host->id,
            'checked_in_at' => now()->subHour(),
        ]);

        $this->actingAsOperations();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function register(string $phone = '+963933000002', ?string $name = 'Sami'): TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/guest-visits', [
            'host_user_id' => $this->host->id,
            'phone' => $phone,
            'name' => $name,
        ]);
    }

    private function travelMinutes(int $minutes): void
    {
        Carbon::setTestNow(now()->addMinutes($minutes));
    }

    public function test_a_guest_checked_out_within_the_grace_costs_nothing(): void
    {
        $id = $this->register()->assertCreated()
            ->assertJsonPath('data.status', 'in_grace')
            ->assertJsonPath('data.bills_host', true)
            ->assertJsonPath('data.grace_seconds_left', 900)
            ->json('data.id');

        $this->travelMinutes(10);

        $this->postJson("/api/v1/admin/reception/guest-visits/{$id}/check-out", [], ['lang' => 'en'])
            ->assertOk()->assertExactJson(['message' => 'The guest has been checked out at no charge.']);

        $this->assertSame(GuestVisitStatus::Left, GuestVisit::find($id)->status);
        $this->assertSame(1, WalkinSession::count());
    }

    public function test_an_unregistered_guest_past_the_grace_is_billed_to_the_host_from_arrival_without_the_host_plan(): void
    {
        $this->subscribe($this->host, $this->planWithHours(40));
        $arrival = now()->copy();
        $id = $this->register()->assertCreated()->json('data.id');

        $this->travelMinutes(16);
        app(GuestVisitService::class)->sweep();

        $visit = GuestVisit::find($id);
        $this->assertSame(GuestVisitStatus::Converted, $visit->status);

        $session = $visit->walkinSession;
        $this->assertSame($this->host->id, $session->user_id);
        $this->assertSame($visit->id, $session->guest_visit_id);
        $this->assertNull($session->membership_id);
        $this->assertTrue($session->checked_in_at->equalTo($arrival));

        // The host is still in their own session, which is the one they see.
        $this->assertTrue(app(OpenCoworkingSessionLocator::class)->for($this->host)->is($this->hostSession));

        // An hour from arrival, at the list rate: the host's plan hours untouched.
        $this->travelMinutes(44);
        $this->postJson("/api/v1/admin/reception/walk-ins/{$session->id}/check-out", ['checked_out_at' => now()->toIso8601String()])
            ->assertOk();

        $session->refresh();
        $this->assertSame(100, (int) $session->price_points);
        $this->assertSame(0, (int) $session->plan_covered_seconds);
        $this->assertSame(0, PlanHourUsage::query()->where('walkin_session_id', $session->id)->count());
    }

    public function test_a_registered_guest_past_the_grace_sits_on_their_own_account(): void
    {
        $guest = User::factory()->create(['phone' => '+963933000002', 'name' => 'Rana']);
        $guest->assignRole('member');

        $id = $this->register(name: null)->assertCreated()
            ->assertJsonPath('data.guest.has_account', true)
            ->assertJsonPath('data.guest.name', 'Rana')
            ->assertJsonPath('data.bills_host', false)
            ->json('data.id');

        $this->travelMinutes(15);
        app(GuestVisitService::class)->sweep();

        $session = GuestVisit::find($id)->walkinSession;
        $this->assertSame($guest->id, $session->user_id);
        $this->assertNull($session->guest_visit_id);
        $this->assertTrue(app(OpenCoworkingSessionLocator::class)->for($guest)->is($session));
    }

    public function test_the_host_cannot_be_checked_out_while_their_guest_is_inside(): void
    {
        $id = $this->register()->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/reception/walk-ins/{$this->hostSession->id}/check-out", ['checked_out_at' => now()->toIso8601String()], ['lang' => 'en'])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'This member still has a guest inside: check the guest out first.']);

        $this->postJson("/api/v1/admin/reception/guest-visits/{$id}/check-out")->assertOk();

        $this->postJson("/api/v1/admin/reception/walk-ins/{$this->hostSession->id}/check-out", ['checked_out_at' => now()->toIso8601String()])
            ->assertOk();
    }

    public function test_a_host_who_declines_is_never_billed(): void
    {
        $id = $this->register()->assertCreated()->json('data.id');

        Sanctum::actingAs($this->host, ['*']);
        $this->getJson('/api/v1/member/guest-visits')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->postJson("/api/v1/member/guest-visits/{$id}/decline")->assertOk();

        $this->travelMinutes(30);
        app(GuestVisitService::class)->sweep();

        $this->assertSame(GuestVisitStatus::Declined, GuestVisit::find($id)->status);
        $this->assertSame(1, WalkinSession::count());
    }

    public function test_another_host_cannot_decline(): void
    {
        $id = $this->register()->assertCreated()->json('data.id');

        $this->actingAsMember();
        $this->postJson("/api/v1/member/guest-visits/{$id}/decline")->assertNotFound();
    }

    public function test_a_second_visit_the_same_day_gets_no_grace(): void
    {
        $first = $this->register()->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/reception/guest-visits/{$first}/check-out")->assertOk();

        $this->travelMinutes(60);
        $this->register()->assertCreated()->assertJsonPath('data.grace_seconds_left', 0);
    }

    public function test_checking_out_past_the_grace_before_the_sweep_bills_from_arrival(): void
    {
        $id = $this->register()->assertCreated()->json('data.id');

        $this->travelMinutes(40);
        $this->postJson("/api/v1/admin/reception/guest-visits/{$id}/check-out", [], ['lang' => 'en'])
            ->assertOk()
            ->assertExactJson(['message' => 'The guest has been checked out and their session billed from their arrival.']);

        $session = GuestVisit::find($id)->walkinSession;
        $this->assertNotNull($session->checked_out_at);
        $this->assertSame(75, (int) $session->price_points);
        $this->assertSame($this->host->id, $session->user_id);
    }

    public function test_a_host_in_a_room_booking_cannot_have_a_billed_guest(): void
    {
        $this->hostSession->forceFill(['checked_out_at' => now()])->save();
        $room = Space::factory()->room()->create(['capacity' => 6]);
        Booking::factory()->create([
            'user_id' => $this->host->id,
            'space_id' => $room->id,
            'start_at' => now()->subHour(),
            'end_at' => now()->addHour(),
            'checked_in_at' => now()->subHour(),
        ]);

        $this->register()->assertStatus(422)->assertExactJson(['message' => 'The host has no active coworking session.']);
    }

    public function test_a_registered_guest_who_starts_their_own_session_ends_the_visit(): void
    {
        $guest = User::factory()->create(['phone' => '+963933000002']);
        $guest->assignRole('member');
        $id = $this->register(name: null)->assertCreated()->json('data.id');

        $this->postJson('/api/v1/admin/reception/walk-ins', ['space_id' => $this->space->id, 'user_id' => $guest->id])->assertCreated();

        $visit = GuestVisit::find($id);
        $this->assertSame(GuestVisitStatus::Converted, $visit->status);
        $this->assertSame($guest->id, $visit->walkinSession->user_id);
    }

    public function test_the_guest_takes_a_seat_while_in_grace(): void
    {
        $this->space->forceFill(['capacity' => 2])->save();
        $this->register()->assertCreated();

        $this->register('+963933000003', 'Third')->assertStatus(422);
    }

    public function test_the_reception_list_shows_guests_and_the_billed_session_names_them(): void
    {
        $id = $this->register()->assertCreated()->json('data.id');
        $this->getJson('/api/v1/admin/reception/guest-visits')->assertOk()->assertJsonPath('data.0.guest.name', 'Sami');

        $this->travelMinutes(15);
        app(GuestVisitService::class)->sweep();

        $this->getJson('/api/v1/admin/reception/guest-visits')->assertOk()->assertJsonCount(0, 'data');
        $guestRow = collect($this->getJson('/api/v1/admin/reception/sessions/active')->assertOk()->json('data'))
            ->firstWhere('guest.visit_id', $id);
        $this->assertSame('Sami', $guestRow['guest']['name']);
        $this->assertSame($this->host->id, $guestRow['member']['id']);
    }
}
