<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;

class SubmitProviderRequestTest extends ProviderTestCase
{
    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = Provider::factory()->create();
        $this->providerAccount($this->provider);
    }

    public function test_a_provider_asks_to_assign_a_public_plan_to_its_company(): void
    {
        $company = $this->clientCompany($this->provider);
        $plan = Plan::factory()->create();

        $this->postJson('/api/v1/provider/requests', [
            'type' => 'plan_assignment',
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'seats' => 5,
            'payer' => 'provider',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'plan_assignment')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payload.seats', 5)
            ->assertJsonPath('data.payload.payer', 'provider');

        $this->assertSame(0, Membership::count());
    }

    public function test_another_providers_private_plan_cannot_be_assigned(): void
    {
        $company = $this->clientCompany($this->provider);
        $plan = Plan::factory()->create(['is_public' => false, 'provider_id' => Provider::factory()->create()->id]);

        $this->postJson('/api/v1/provider/requests', [
            'type' => 'plan_assignment', 'company_id' => $company->id, 'plan_id' => $plan->id, 'seats' => 1, 'payer' => 'company',
        ])->assertStatus(422)->assertJsonValidationErrors('plan_id');
    }

    public function test_its_own_approved_private_plan_can_be_assigned(): void
    {
        $company = $this->clientCompany($this->provider);
        $plan = Plan::factory()->create(['is_public' => false, 'provider_id' => $this->provider->id]);

        $this->postJson('/api/v1/provider/requests', [
            'type' => 'plan_assignment', 'company_id' => $company->id, 'plan_id' => $plan->id, 'seats' => 1, 'payer' => 'company',
        ])->assertCreated();
    }

    public function test_a_request_on_someone_elses_company_is_refused(): void
    {
        $theirs = $this->clientCompany(Provider::factory()->create());

        $this->postJson('/api/v1/provider/requests', [
            'type' => 'wallet_top_up', 'company_id' => $theirs->id, 'points' => 100, 'payer' => 'company',
        ])->assertStatus(422)->assertJsonValidationErrors('company_id');
    }

    public function test_a_company_with_a_live_subscription_cannot_be_assigned_another(): void
    {
        $company = $this->clientCompany($this->provider);
        Membership::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        $this->postJson('/api/v1/provider/requests', [
            'type' => 'plan_assignment', 'company_id' => $company->id, 'plan_id' => Plan::factory()->create()->id, 'seats' => 1, 'payer' => 'company',
        ])->assertStatus(422)->assertJsonValidationErrors('company_id');
    }

    public function test_the_same_employee_cannot_be_requested_twice(): void
    {
        $company = $this->clientCompany($this->provider);
        $body = ['type' => 'company_member', 'company_id' => $company->id, 'phone' => '+963955555555', 'name' => 'Sara'];

        $this->postJson('/api/v1/provider/requests', $body)->assertCreated();
        $this->postJson('/api/v1/provider/requests', $body)->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_a_member_request_answers_the_same_whether_or_not_the_phone_has_an_account(): void
    {
        $company = $this->clientCompany($this->provider);
        User::factory()->create(['phone' => '+963966666666']);

        $this->postJson('/api/v1/provider/requests', ['type' => 'company_member', 'company_id' => $company->id, 'phone' => '+963966666666', 'name' => 'A'])
            ->assertCreated();
        $this->postJson('/api/v1/provider/requests', ['type' => 'company_member', 'company_id' => $company->id, 'phone' => '+963977777777', 'name' => 'B'])
            ->assertCreated();
    }

    public function test_an_admin_can_only_be_requested_for_an_existing_member(): void
    {
        $company = $this->clientCompany($this->provider);
        $outsider = User::factory()->create();

        $this->postJson('/api/v1/provider/requests', ['type' => 'company_admin', 'company_id' => $company->id, 'user_id' => $outsider->id])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');
    }

    public function test_an_admin_request_with_a_string_user_id_is_deduplicated(): void
    {
        $company = $this->clientCompany($this->provider);
        $member = User::factory()->create();
        $company->members()->attach($member->id);
        $body = ['type' => 'company_admin', 'company_id' => $company->id];

        $this->postJson('/api/v1/provider/requests', $body + ['user_id' => (string) $member->id])->assertCreated();
        $this->postJson('/api/v1/provider/requests', $body + ['user_id' => $member->id])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');
    }

    public function test_a_plan_design_is_validated_like_an_admin_plan(): void
    {
        $this->postJson('/api/v1/provider/requests', ['type' => 'plan_design', 'name' => ['en' => 'X', 'ar' => 'س']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_subscription', 'price_points', 'duration_days']);

        $this->postJson('/api/v1/provider/requests', [
            'type' => 'plan_design',
            'name' => ['en' => 'Platform Teams', 'ar' => 'فرق بلاتفورم'],
            'is_subscription' => true,
            'price_points' => 900,
            'duration_days' => 30,
        ])->assertCreated()->assertJsonPath('data.company', null);
    }

    public function test_a_provider_lists_shows_and_cancels_its_own_requests_only(): void
    {
        $mine = ProviderRequest::factory()->create(['provider_id' => $this->provider->id, 'company_id' => $this->clientCompany($this->provider)->id]);
        $theirs = ProviderRequest::factory()->create();

        $this->getJson('/api/v1/provider/requests')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/provider/requests/{$theirs->id}")->assertNotFound();
        $this->patchJson("/api/v1/provider/requests/{$theirs->id}/cancel")->assertNotFound();

        $this->patchJson("/api/v1/provider/requests/{$mine->id}/cancel")
            ->assertOk()->assertJsonPath('message', __('api.provider.request_cancelled'));
        $this->assertSame(ProviderRequestStatus::Cancelled, $mine->fresh()->status);

        $this->patchJson("/api/v1/provider/requests/{$mine->id}/cancel")->assertStatus(409);
    }

    public function test_the_plans_a_provider_may_assign_are_public_ones_and_its_own(): void
    {
        $public = Plan::factory()->create();
        $own = Plan::factory()->create(['is_public' => false, 'provider_id' => $this->provider->id]);
        Plan::factory()->create(['is_public' => false]);
        Plan::factory()->create(['is_public' => false, 'provider_id' => Provider::factory()->create()->id]);
        Plan::factory()->create(['is_active' => false]);

        $ids = collect($this->getJson('/api/v1/provider/plans')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$public->id, $own->id])->sort()->values()->all(), $ids);
    }
}
