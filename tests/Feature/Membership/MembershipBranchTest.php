<?php

namespace Tests\Feature\Membership;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserBranchMembership;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A subscription holds its seats in one branch's co-working pool, so every
 * membership belongs to a branch: a company's own branch, else the member's
 * home branch, else the default branch — unless one is given
 * (docs/decisions/coworking-seat-pool.md).
 */
class MembershipBranchTest extends TestCase
{
    use RefreshDatabase;

    private Branch $default;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('default_branch_id');
        $this->default = Branch::factory()->create(['is_default' => true]);
    }

    public function test_a_company_membership_belongs_to_the_companys_branch(): void
    {
        $company = Company::factory()->create();

        $membership = Membership::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        $this->assertSame($company->branch_id, $membership->branch_id);
    }

    public function test_a_members_membership_belongs_to_their_home_branch(): void
    {
        $user = User::factory()->create();
        $home = Branch::factory()->create();
        UserBranchMembership::query()->create(['user_id' => $user->id, 'branch_id' => $home->id, 'is_home_branch' => true]);

        $membership = Membership::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);

        $this->assertSame($home->id, $membership->branch_id);
    }

    public function test_without_a_home_branch_it_belongs_to_the_default_branch(): void
    {
        $membership = Membership::factory()->create();

        $this->assertSame($this->default->id, $membership->branch_id);
    }

    public function test_a_given_branch_is_kept(): void
    {
        $other = Branch::factory()->create();

        $membership = Membership::factory()->create(['branch_id' => $other->id]);

        $this->assertSame($other->id, $membership->branch_id);
    }

    public function test_a_membership_holds_one_seat_unless_told_otherwise(): void
    {
        $this->assertSame(1, Membership::factory()->create()->fresh()->seats);
    }

    /** Rows from before the column existed get the same branch by the same rule. */
    public function test_the_backfill_gives_existing_memberships_their_branch(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $home = Branch::factory()->create();
        UserBranchMembership::query()->create(['user_id' => $user->id, 'branch_id' => $home->id, 'is_home_branch' => true]);
        $loner = User::factory()->create();

        $companyRow = Membership::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        $userRow = Membership::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);
        $lonerRow = Membership::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $loner->id]);
        DB::table('memberships')->update(['branch_id' => null]);

        (require database_path('migrations/2026_09_30_100001_backfill_membership_branches.php'))->up();

        $this->assertSame($company->branch_id, DB::table('memberships')->where('id', $companyRow->id)->value('branch_id'));
        $this->assertSame($home->id, DB::table('memberships')->where('id', $userRow->id)->value('branch_id'));
        $this->assertSame($this->default->id, DB::table('memberships')->where('id', $lonerRow->id)->value('branch_id'));
    }
}
