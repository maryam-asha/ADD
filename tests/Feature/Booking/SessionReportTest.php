<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Membership\Services\ResourceQuotaService;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class SessionReportTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 13:00:00', 'Asia/Damascus')->utc());
        $this->space = $this->coworkingSpace(hourlyRatePoints: 100);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse("2026-08-17 {$time}", 'Asia/Damascus')->utc();
    }

    private function walkIn(User $member, ?Membership $membership, string $in, ?string $out = null): WalkinSession
    {
        $session = WalkinSession::factory()->create([
            'space_id' => $this->space->id,
            'user_id' => $member->id,
            'membership_id' => $membership?->id,
            'checked_in_at' => $this->at($in),
        ]);

        if ($out !== null) {
            app(SessionClosureService::class)->closeOut($session, $this->at($out));
        }

        return $session->fresh();
    }

    private function report(string $type, int $id): array
    {
        return $this->withHeader('lang', 'en')->getJson("/api/v1/member/coworking-sessions/{$type}/{$id}/report")->assertOk()->json('data');
    }

    public function test_a_session_the_plan_covered_in_full_is_one_plan_line_and_nothing_due(): void
    {
        $member = $this->actingAsMember();
        $membership = $this->subscribe($member, $this->planWithHours(40));
        $session = $this->walkIn($member, $membership, '09:00', '12:00');

        $report = $this->report('walkin', $session->id);

        $this->assertCount(1, $report['lines']);
        $this->assertSame(['time', 'plan', 3], [$report['lines'][0]['kind'], $report['lines'][0]['source'], $report['lines'][0]['quantity']]);
        $this->assertSame(3, $report['plan']['hours_used']);
        $this->assertSame(37, $report['plan']['hours_remaining']);
        $this->assertSame('0.00', $report['totals']['amount_owed']);
        $this->assertSame('paid', $report['totals']['payment_state']);
        $this->assertSame(10800, $report['session']['duration_seconds']);
    }

    public function test_a_partly_covered_session_splits_plan_time_from_charged_time(): void
    {
        $member = $this->actingAsMember();
        $session = $this->walkIn($member, $this->subscribe($member, $this->planWithHours(2)), '09:00', '12:00');

        $lines = $this->report('walkin', $session->id)['lines'];

        $this->assertSame(['plan', 2, null], [$lines[0]['source'], $lines[0]['quantity'], $lines[0]['amount']]);
        $this->assertSame(['charge', 1, 100, '10.00'], [$lines[1]['source'], $lines[1]['quantity'], $lines[1]['points'], $lines[1]['amount']]);
    }

    public function test_printing_during_the_session_is_its_own_line(): void
    {
        $member = $this->actingAsMember();
        $plan = $this->planWithHours(40);
        $printing = ResourceType::where('slug', 'printing')->firstOrFail();
        $grant = $plan->resourceGrants()->create(['resource_type_id' => $printing->id, 'quantity' => 100, 'order' => 1]);
        $membership = $this->subscribe($member, $plan);
        $session = $this->walkIn($member, $membership, '09:00');
        app(ResourceQuotaService::class)->consume($grant, $membership, 20, $member)->sessionable()->associate($session)->save();
        app(SessionClosureService::class)->closeOut($session, $this->at('12:00'));

        $lines = collect($this->report('walkin', $session->id)['lines']);

        $printed = $lines->firstWhere('kind', 'resource_consumption');
        $this->assertSame(20, $printed['quantity']);
        $this->assertSame('plan', $printed['source']);
        $this->assertNotNull($printed['label']);
        $this->assertSame(1, $lines->where('kind', 'time')->count()); // the hour row is the time line, not repeated
    }

    public function test_an_open_session_reports_time_so_far_and_no_totals(): void
    {
        $member = $this->actingAsMember();
        $session = $this->walkIn($member, null, '12:00');

        $report = $this->report('walkin', $session->id);

        $this->assertSame([null, 1], [$report['lines'][0]['source'], $report['lines'][0]['quantity']]);
        $this->assertNull($report['totals']);
    }

    public function test_a_booking_reports_its_stored_charge(): void
    {
        $member = $this->actingAsMember();
        $booking = Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => $this->space->id,
            'checked_in_at' => $this->at('09:00'),
        ]);
        app(SessionClosureService::class)->closeOut($booking, $this->at('11:00'));

        $lines = $this->report('booking', $booking->id)['lines'];

        $this->assertCount(1, $lines);
        $this->assertSame(['charge', 2, (string) $booking->fresh()->amount_owed], [$lines[0]['source'], $lines[0]['quantity'], $lines[0]['amount']]);
    }

    public function test_a_member_cannot_read_another_members_report(): void
    {
        $session = $this->walkIn(User::factory()->create(), null, '09:00', '10:00');
        $this->actingAsMember();

        $this->getJson("/api/v1/member/coworking-sessions/walkin/{$session->id}/report")->assertNotFound();
    }

    public function test_a_booking_that_never_started_has_no_report(): void
    {
        $member = $this->actingAsMember();
        $booking = Booking::factory()->create(['user_id' => $member->id, 'space_id' => $this->space->id]);
        $this->assertNull($booking->checked_in_at);

        $this->getJson("/api/v1/member/coworking-sessions/booking/{$booking->id}/report")->assertNotFound();

        $this->actingAsOperations();

        $this->getJson("/api/v1/admin/reception/sessions/booking/{$booking->id}/report")->assertNotFound();
    }

    public function test_reception_reads_any_report(): void
    {
        $session = $this->walkIn(User::factory()->create(), null, '09:00', '10:00');
        $this->actingAsOperations();

        $this->getJson("/api/v1/admin/reception/sessions/walkin/{$session->id}/report")
            ->assertOk()
            ->assertJsonPath('data.session.id', $session->id);
    }
}
