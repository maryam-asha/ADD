<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\ResourceConsumption;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Membership\Services\PlanHourCoverageService;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class ResourceConsumptionSessionLinkTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function printingId(): int
    {
        return ResourceType::where('slug', 'printing')->value('id');
    }

    private function planWithPrinting(): Plan
    {
        $plan = $this->planWithHours(40);
        $plan->resourceGrants()->create(['resource_type_id' => $this->printingId(), 'quantity' => 100, 'order' => 1]);

        return $plan;
    }

    private function recordPrinting(array $owner): ResourceConsumption
    {
        $this->actingAsOperations();
        $id = $this->postJson('/api/v1/admin/reception/resource-consumptions', $owner + [
            'resource_type_id' => $this->printingId(),
            'quantity' => 20,
        ])->assertCreated()->json('data.id');

        return ResourceConsumption::findOrFail($id);
    }

    public function test_printing_during_an_open_coworking_session_lands_on_that_session(): void
    {
        $member = User::factory()->create();
        $this->subscribe($member, $this->planWithPrinting());
        $session = WalkinSession::factory()->create(['space_id' => $this->coworkingSpace()->id, 'user_id' => $member->id]);

        $consumption = $this->recordPrinting(['user_id' => $member->id]);

        $this->assertTrue($consumption->sessionable->is($session));
        $this->assertSame(1, $session->resourceConsumptions()->count());
    }

    public function test_printing_with_no_open_session_is_not_linked(): void
    {
        $member = User::factory()->create();
        $this->subscribe($member, $this->planWithPrinting());
        $space = $this->coworkingSpace();

        // Sessions exist, but none of them is this member's open one: theirs
        // has ended, and someone else's is still running.
        WalkinSession::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'checked_in_at' => Carbon::parse('2026-08-17 07:00', 'Asia/Damascus')->utc(),
            'checked_out_at' => Carbon::parse('2026-08-17 09:00', 'Asia/Damascus')->utc(),
        ]);
        WalkinSession::factory()->create(['space_id' => $space->id, 'user_id' => User::factory()->create()->id]);

        $this->assertNull($this->recordPrinting(['user_id' => $member->id])->sessionable_id);
    }

    public function test_company_consumption_is_never_linked(): void
    {
        $company = Company::factory()->create();

        // A user who shares the company's id and is mid-session: a controller
        // that read the company id as a user id would link to this session.
        $twin = User::find($company->id) ?? User::factory()->create(['id' => $company->id]);
        WalkinSession::factory()->create(['space_id' => $this->coworkingSpace()->id, 'user_id' => $twin->id]);

        Membership::create([
            'plan_id' => $this->planWithPrinting()->id,
            'owner_type' => OwnerType::Company,
            'owner_id' => $company->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addDays(20),
        ]);

        $this->assertNull($this->recordPrinting(['company_id' => $company->id])->sessionable_id);
    }

    public function test_the_plan_hours_a_walk_in_used_are_linked_to_it(): void
    {
        $member = User::factory()->create();
        $membership = $this->subscribe($member, $this->planWithHours(40));
        $session = WalkinSession::factory()->create([
            'space_id' => $this->coworkingSpace()->id,
            'user_id' => $member->id,
            'membership_id' => $membership->id,
            'checked_in_at' => Carbon::parse('2026-08-17 08:00', 'Asia/Damascus')->utc(),
        ]);

        app(SessionClosureService::class)->closeOut($session, Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc());

        $hours = ResourceConsumption::where('membership_id', $membership->id)->sole();
        $this->assertTrue($hours->sessionable->is($session));
        $this->assertSame(PlanHourCoverageService::RESOURCE_TYPE_SLUG, $hours->grant->resourceType->slug);
    }
}
