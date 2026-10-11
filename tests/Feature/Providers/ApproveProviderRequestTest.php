<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

class ApproveProviderRequestTest extends ProviderTestCase
{
    private Provider $provider;

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = Provider::factory()->create();
        $this->account = $this->providerAccount($this->provider);
    }

    private function request(ProviderRequestType $type, array $payload, ?int $companyId = null): ProviderRequest
    {
        return ProviderRequest::factory()->create([
            'provider_id' => $this->provider->id,
            'submitted_by' => $this->account->id,
            'type' => $type,
            'company_id' => $companyId,
            'payload' => $payload,
        ]);
    }

    public function test_approving_an_assignment_activates_the_plan_for_the_company_without_charging_it(): void
    {
        $branch = $this->branchWithSeats(10);
        $company = $this->clientCompany($this->provider, ['branch_id' => $branch->id]);
        $plan = Plan::factory()->create(['price_points' => 900]);
        $request = $this->request(ProviderRequestType::PlanAssignment, ['plan_id' => $plan->id, 'seats' => 3, 'payer' => 'provider'], $company->id);
        $admin = $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")
            ->assertOk()
            ->assertJsonPath('message', __('api.admin.provider_request_approved'));

        $membership = Membership::query()->where('owner_type', OwnerType::Company)->where('owner_id', $company->id)->sole();
        $this->assertSame(3, $membership->seats);
        $request->refresh();
        $this->assertSame(ProviderRequestStatus::Approved, $request->status);
        $this->assertSame($membership->id, $request->result_id);
        $this->assertSame($admin->id, $request->reviewed_by);
        $this->assertTrue(NotificationLog::query()->where('user_id', $this->account->id)->where('template_key', 'provider.request_approved')->exists());
    }

    public function test_an_assignment_whose_company_got_a_subscription_meanwhile_stays_pending(): void
    {
        $company = $this->clientCompany($this->provider);
        $request = $this->request(ProviderRequestType::PlanAssignment, ['plan_id' => Plan::factory()->create()->id, 'seats' => 1, 'payer' => 'company'], $company->id);
        Membership::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.admin.membership_already_active'));

        $this->assertTrue($request->fresh()->isPending());
        $this->assertSame(1, Membership::count());
    }

    public function test_an_assignment_into_a_full_pool_is_refused_unless_the_reviewer_overrides(): void
    {
        $branch = $this->branchWithSeats(1);
        $company = $this->clientCompany($this->provider, ['branch_id' => $branch->id]);
        $request = $this->request(ProviderRequestType::PlanAssignment, ['plan_id' => Plan::factory()->create()->id, 'seats' => 5, 'payer' => 'company'], $company->id);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")
            ->assertStatus(422)->assertJsonPath('message', __('api.membership.seat_pool_full'));
        $this->assertTrue($request->fresh()->isPending());

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve", ['override_capacity' => true])->assertOk();
    }

    public function test_approving_twice_creates_one_subscription(): void
    {
        $branch = $this->branchWithSeats(10);
        $company = $this->clientCompany($this->provider, ['branch_id' => $branch->id]);
        $request = $this->request(ProviderRequestType::PlanAssignment, ['plan_id' => Plan::factory()->create()->id, 'seats' => 1, 'payer' => 'company'], $company->id);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();
        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertStatus(409);

        $this->assertSame(1, Membership::count());
    }

    public function test_a_request_on_a_company_deactivated_meanwhile_is_refused(): void
    {
        $company = $this->clientCompany($this->provider);
        $request = $this->request(ProviderRequestType::WalletTopUp, ['points' => 100, 'payer' => 'company'], $company->id);
        $company->update(['status' => CompanyStatus::Inactive]);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")
            ->assertStatus(422)->assertJsonPath('message', __('api.provider.company_unavailable'));
    }

    public function test_approving_a_design_creates_a_private_plan_of_the_provider(): void
    {
        $request = $this->request(ProviderRequestType::PlanDesign, [
            'name' => ['en' => 'Platform Teams', 'ar' => 'فرق بلاتفورم'],
            'is_subscription' => true,
            'price_points' => 900,
            'duration_days' => 30,
            'features' => [['name' => ['en' => 'Desk', 'ar' => 'مكتب']]],
        ]);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();

        $plan = Plan::query()->findOrFail($request->fresh()->result_id);
        $this->assertFalse($plan->is_public);
        $this->assertSame($this->provider->id, $plan->provider_id);
        $this->assertCount(1, $plan->features);
    }

    public function test_approving_a_top_up_credits_the_company_wallet(): void
    {
        $company = $this->clientCompany($this->provider);
        $request = $this->request(ProviderRequestType::WalletTopUp, ['points' => 500, 'payer' => 'provider'], $company->id);
        $admin = $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();

        $wallet = Wallet::query()->where('owner_type', OwnerType::Company)->where('owner_id', $company->id)->sole();
        $this->assertSame(500, app(WalletService::class)->generalBalance($wallet));
        $this->assertSame($admin->id, $wallet->transactions()->sole()->performed_by_user_id);
    }

    public function test_a_rejection_needs_a_reason_and_tells_the_provider(): void
    {
        $request = $this->request(ProviderRequestType::WalletTopUp, ['points' => 100, 'payer' => 'company'], $this->clientCompany($this->provider)->id);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/reject")->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/reject", ['reason' => 'No contract yet'])
            ->assertOk()->assertJsonPath('message', __('api.admin.provider_request_rejected'));

        $request->refresh();
        $this->assertSame(ProviderRequestStatus::Rejected, $request->status);
        $this->assertSame('No contract yet', $request->rejection_reason);
        $this->assertTrue(NotificationLog::query()->where('user_id', $this->account->id)->where('template_key', 'provider.request_rejected')->exists());
    }

    public function test_the_admin_queue_lists_pending_requests_by_default(): void
    {
        $company = $this->clientCompany($this->provider);
        $this->request(ProviderRequestType::WalletTopUp, ['points' => 1, 'payer' => 'company'], $company->id);
        $this->request(ProviderRequestType::WalletTopUp, ['points' => 2, 'payer' => 'company'], $company->id)
            ->forceFill(['status' => ProviderRequestStatus::Rejected])->save();
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/provider-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/provider-requests?status=rejected')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_deciding_follows_the_permission_so_operations_can_be_given_it(): void
    {
        $request = $this->request(ProviderRequestType::WalletTopUp, ['points' => 100, 'payer' => 'company'], $this->clientCompany($this->provider)->id);
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertForbidden();

        Role::findByName('operations', 'web')->givePermissionTo('provider-requests.approve');

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();
    }
}
