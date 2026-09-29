<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Membership;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What reception and the dashboard read to see the branch's co-working seats:
 * how many are open, taken and free now, and over the range asked about
 * (docs/decisions/coworking-seat-pool.md).
 */
class CoworkingPoolControllerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00', 'Asia/Damascus'));
        $this->branch = Branch::factory()->create();

        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_pool_now_and_over_the_rest_of_the_day(): void
    {
        $space = Space::factory()->create([
            'building_id' => Building::factory()->create(['branch_id' => $this->branch->id])->id,
            'capacity' => 10,
        ]);
        Membership::factory()->count(3)->create(['branch_id' => $this->branch->id]);
        WalkinSession::factory()->create(['space_id' => $space->id]);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/branches/{$this->branch->id}/coworking-pool")
            ->assertOk();

        $response->assertJsonPath('data.capacity_now', 10)
            ->assertJsonPath('data.usage_now', 4)
            ->assertJsonPath('data.free_now', 6)
            ->assertJsonPath('data.min_free', 6)
            ->assertJsonPath('data.from', '2026-10-01T09:00:00+03:00')
            ->assertJsonPath('data.to', '2026-10-02T00:00:00+03:00');

        $this->assertSame(
            ['start', 'end', 'capacity', 'usage', 'free'],
            array_keys($response->json('data.segments.0')),
        );
    }

    /**
     * Left to itself the range ends at today's closing time, not midnight: a
     * room lent until 23:00 on a branch closing at 23:00 must not make the
     * closed hour read as "1 seat free today".
     */
    public function test_by_default_the_range_ends_at_todays_closing_time(): void
    {
        BusinessHour::factory()->create([
            'branch_id' => $this->branch->id,
            'day_of_week' => DayOfWeek::Thursday,
            'open_time' => '08:00',
            'close_time' => '22:00',
        ]);

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/branches/{$this->branch->id}/coworking-pool")
            ->assertOk()
            ->assertJsonPath('data.to', '2026-10-01T22:00:00+03:00');
    }

    /** A range asked for in the district's wall-clock. */
    public function test_a_range_is_read_in_local_time(): void
    {
        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/branches/{$this->branch->id}/coworking-pool?from=2026-10-05 08:00&to=2026-10-05 20:00")
            ->assertOk()
            ->assertJsonPath('data.from', '2026-10-05T08:00:00+03:00')
            ->assertJsonPath('data.to', '2026-10-05T20:00:00+03:00');
    }

    public function test_a_member_cannot_read_the_pool(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson("/api/v1/admin/branches/{$this->branch->id}/coworking-pool")->assertForbidden();
    }
}
