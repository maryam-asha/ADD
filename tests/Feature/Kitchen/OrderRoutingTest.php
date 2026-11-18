<?php

namespace Tests\Feature\Kitchen;

use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\OrderRoute;
use App\Domain\Kitchen\Services\OrderRouting;
use App\Domain\Kitchen\Services\RouteDecision;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where an order goes (spec decisions 1 and 2): office staff and members with
 * a bill straight to the kitchen, everyone else through reception.
 */
class OrderRoutingTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Branch $branch;

    private Building $building;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = User::factory()->create();
        $this->branch = Branch::factory()->create();
        $this->building = Building::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function staffOf(bool $doorAccess = true): Company
    {
        $company = Company::factory()->create();
        $this->member->companies()->attach($company->id, ['door_access_enabled' => $doorAccess]);

        return $company;
    }

    private function lease(Company $company, ?Building $building = null, array $extra = []): OfficeLease
    {
        $space = Space::factory()->create(['building_id' => ($building ?? $this->building)->id]);

        return OfficeLease::factory()->create(['company_id' => $company->id, 'space_id' => $space->id, ...$extra]);
    }

    private function route(): RouteDecision
    {
        return app(OrderRouting::class)->for($this->member, null, $this->branch->id);
    }

    public function test_member_with_a_session_takes_the_kitchen_route_and_may_put_it_on_the_bill(): void
    {
        $space = Space::factory()->create(['building_id' => $this->building->id]);
        WalkinSession::factory()->create(['user_id' => $this->member->id, 'space_id' => $space->id]);

        $decision = app(OrderRouting::class)->for($this->member, null, null);

        $this->assertSame(OrderRoute::Kitchen, $decision->route);
        $this->assertSame(['bill', 'wallet', 'card'], $decision->methods);
        $this->assertCount(1, $decision->bills);
        $this->assertSame($this->branch->id, $decision->branchId);
    }

    public function test_office_staff_without_a_session_go_to_the_kitchen(): void
    {
        $lease = $this->lease($this->staffOf());

        $decision = $this->route();

        $this->assertSame(OrderRoute::Kitchen, $decision->route);
        $this->assertSame(['wallet', 'card'], $decision->methods);
        $this->assertSame($lease->id, $decision->lease?->id);
    }

    public function test_office_lease_names_the_branch_when_nothing_else_does(): void
    {
        $other = Building::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $this->lease($this->staffOf(), $other);

        $decision = app(OrderRouting::class)->for($this->member, null, null);

        $this->assertSame($other->branch_id, $decision->branchId);
    }

    public function test_lease_in_another_branch_is_not_office_staff(): void
    {
        $other = Building::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $this->lease($this->staffOf(), $other);

        $decision = $this->route();

        $this->assertSame(OrderRoute::Reception, $decision->route);
        $this->assertNull($decision->lease);
    }

    public function test_member_without_door_access_is_not_office_staff(): void
    {
        $this->lease($this->staffOf(false));

        $decision = $this->route();

        $this->assertSame(OrderRoute::Reception, $decision->route);
        $this->assertNull($decision->lease);
    }

    public function test_ended_or_terminated_lease_is_not_office_staff(): void
    {
        $company = $this->staffOf();
        $this->lease($company, null, [
            'starts_on' => now()->subYear()->toDateString(),
            'ends_on' => now()->subDay()->toDateString(),
            'status' => OfficeLeaseStatus::Ended,
        ]);
        $this->lease($company, null, [
            'starts_on' => now()->subMonths(3)->toDateString(),
            'terminated_on' => now()->subDay()->toDateString(),
            'status' => OfficeLeaseStatus::Terminated,
        ]);

        $this->assertSame(OrderRoute::Reception, $this->route()->route);
    }

    public function test_anyone_else_goes_to_reception_and_may_pay_cash(): void
    {
        $decision = $this->route();

        $this->assertSame(OrderRoute::Reception, $decision->route);
        $this->assertSame(['wallet', 'card', 'cash'], $decision->methods);
        $this->assertSame([], $decision->bills);
    }

    public function test_company_wallet_is_listed_for_its_staff(): void
    {
        $company = $this->staffOf();
        app(WalletService::class)->creditGeneral(
            Wallet::query()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]),
            500,
            WalletTransactionSource::TopUp,
        );

        $wallets = $this->route()->wallets;

        $this->assertSame([OwnerType::Company->value], array_column($wallets, 'owner_type'));
        $this->assertSame($company->id, $wallets[0]['owner_id']);
        $this->assertSame(500, $wallets[0]['usable_balance']);
    }
}
