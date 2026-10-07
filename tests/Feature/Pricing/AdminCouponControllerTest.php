<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponLedger;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Coupons are managed from the dashboard: anyone on it can look one up and
 * see who used it; only an admin creates, changes or deletes one — a code
 * is money off (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 */
class AdminCouponControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'Asia/Damascus')->utc());
    }

    private function signInAs(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => ' welcome20 ',
            'name' => ['ar' => 'أهلاً', 'en' => 'Welcome'],
            'discount_type' => 'percentage',
            'discount_value' => 20,
        ], $overrides);
    }

    public function test_an_admin_creates_a_coupon_for_specific_targets_and_members(): void
    {
        $admin = $this->signInAs('admin');
        $plan = Plan::factory()->create();
        $space = Space::factory()->room()->create();
        $member = User::factory()->create();

        $response = $this->postJson('/api/v1/admin/coupons', $this->payload([
            'max_discount_points' => 50,
            'usage_limit' => 10,
            'starts_at' => '2026-10-05 09:00',
            'ends_at' => '2026-10-12 09:00',
            'targets' => [
                ['purpose' => 'plan_purchase', 'plan_id' => $plan->id],
                ['purpose' => 'booking', 'space_id' => $space->id],
                ['purpose' => 'coworking_session'],
            ],
            'user_ids' => [$member->id],
        ]))->assertCreated();

        $response->assertJsonPath('data.code', 'WELCOME20')
            ->assertJsonPath('data.source', 'admin')
            ->assertJsonPath('data.per_user_limit', 1)
            ->assertJsonPath('data.usage_limit', 10)
            ->assertJsonPath('data.user_ids', [$member->id])
            ->assertJsonCount(3, 'data.targets');

        $coupon = Coupon::where('code', 'WELCOME20')->sole();
        $this->assertSame($admin->id, $coupon->created_by);
        $this->assertSame('2026-10-12 06:00:00', $coupon->ends_at->utc()->toDateTimeString());
        $this->assertTrue(Activity::where('description', 'coupon_created')->where('subject_id', $coupon->id)->exists());
    }

    /** No stacking on an offer unless the admin asks for it: the member gets the better of the two. */
    public function test_a_coupon_does_not_combine_with_offers_unless_asked(): void
    {
        $this->signInAs('admin');

        $this->postJson('/api/v1/admin/coupons', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.combinable_with_offers', false);

        $this->postJson('/api/v1/admin/coupons', $this->payload(['code' => 'STACK', 'combinable_with_offers' => true]))
            ->assertCreated()
            ->assertJsonPath('data.combinable_with_offers', true);
    }

    public function test_a_coupon_may_be_unlimited_per_member(): void
    {
        $this->signInAs('admin');

        $this->postJson('/api/v1/admin/coupons', $this->payload(['per_user_limit' => null]))
            ->assertCreated()
            ->assertJsonPath('data.per_user_limit', null);
    }

    public function test_a_coupon_is_refused_when_it_cannot_be_used_as_written(): void
    {
        $this->signInAs('admin');
        Coupon::factory()->create(['code' => 'TAKEN']);
        $plan = Plan::factory()->create();

        $this->postJson('/api/v1/admin/coupons', $this->payload(['code' => 'taken']))
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->postJson('/api/v1/admin/coupons', $this->payload(['code' => 'has space']))
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->postJson('/api/v1/admin/coupons', $this->payload(['discount_value' => 101]))
            ->assertUnprocessable()->assertJsonValidationErrors(['discount_value']);
        $this->postJson('/api/v1/admin/coupons', $this->payload(['discount_value' => 0]))
            ->assertUnprocessable()->assertJsonValidationErrors(['discount_value']);
        $this->postJson('/api/v1/admin/coupons', $this->payload(['starts_at' => '2026-10-12 09:00', 'ends_at' => '2026-10-05 09:00']))
            ->assertUnprocessable()->assertJsonValidationErrors(['ends_at']);
        // A target names one item, and one its purpose can be bought with.
        $this->postJson('/api/v1/admin/coupons', $this->payload(['targets' => [['purpose' => 'booking', 'plan_id' => $plan->id]]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['targets.0']);
        $this->postJson('/api/v1/admin/coupons', $this->payload(['targets' => [['purpose' => 'booking', 'space_id' => Space::factory()->create()->id, 'space_type' => 'room']]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['targets.0']);
        // A wallet top-up is never discounted.
        $this->postJson('/api/v1/admin/coupons', $this->payload(['targets' => [['purpose' => 'wallet_topup']]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['targets.0.purpose']);

        $this->assertSame(1, Coupon::count());
    }

    public function test_an_admin_updates_a_coupon_and_replaces_its_targets_and_members(): void
    {
        $this->signInAs('admin');
        $coupon = Coupon::factory()->create(['code' => 'OLD']);
        $coupon->targets()->create(['purpose' => PricingPurpose::Booking]);
        $coupon->users()->attach(User::factory()->create());
        $member = User::factory()->create();

        $this->putJson("/api/v1/admin/coupons/{$coupon->id}", $this->payload([
            'code' => 'old',
            'discount_type' => 'amount_off',
            'discount_value' => 30,
            'targets' => [['purpose' => 'event_registration']],
            'user_ids' => [$member->id],
        ]))->assertOk()->assertJsonPath('message', __('api.admin.coupon_updated'));

        $coupon->refresh();
        $this->assertSame(30, $coupon->discount_value);
        $this->assertSame([PricingPurpose::EventRegistration], $coupon->targets->pluck('purpose')->all());
        $this->assertSame([$member->id], $coupon->users->pluck('id')->all());
        $this->assertTrue(Activity::where('description', 'coupon_updated')->where('subject_id', $coupon->id)->exists());
    }

    public function test_an_update_that_leaves_out_targets_and_members_keeps_them(): void
    {
        $this->signInAs('admin');
        $coupon = Coupon::factory()->create(['code' => 'KEEP']);
        $coupon->targets()->create(['purpose' => PricingPurpose::Booking]);
        $coupon->users()->attach($member = User::factory()->create());

        $this->putJson("/api/v1/admin/coupons/{$coupon->id}", $this->payload(['code' => 'KEEP']))->assertOk();

        $this->assertCount(1, $coupon->targets()->get());
        $this->assertSame([$member->id], $coupon->users()->pluck('users.id')->all());
    }

    public function test_the_list_is_paginated_filtered_and_counts_live_uses(): void
    {
        $this->signInAs('operations');
        $running = Coupon::factory()->create(['code' => 'SUMMER', 'usage_limit' => 10]);
        Coupon::factory()->create(['code' => 'WINTER', 'ends_at' => now()->subDay()]);
        Coupon::factory()->create(['code' => 'SPRING', 'is_active' => false]);
        $ledger = app(CouponLedger::class);
        $ledger->record($running, User::factory()->create(), Booking::factory()->create(), PricingPurpose::Booking, 100, 10);
        $ledger->record($running, User::factory()->create(), Booking::factory()->create(['status' => BookingStatus::Cancelled]), PricingPurpose::Booking, 100, 10);

        $page = $this->getJson('/api/v1/admin/coupons')->assertOk();
        $page->assertJsonCount(3, 'data')->assertJsonStructure(['data', 'links', 'meta']);
        $this->assertSame(1, collect($page->json('data'))->firstWhere('code', 'SUMMER')['used']);

        $this->assertSame(['SUMMER'], collect($this->getJson('/api/v1/admin/coupons?search=summ')->json('data'))->pluck('code')->all());
        $this->assertSame(['WINTER'], collect($this->getJson('/api/v1/admin/coupons?state=ended')->json('data'))->pluck('code')->all());
        $this->assertSame(['SPRING'], collect($this->getJson('/api/v1/admin/coupons?is_active=0')->json('data'))->pluck('code')->all());

        $this->getJson("/api/v1/admin/coupons/{$running->id}")->assertOk()->assertJsonPath('data.code', 'SUMMER');
    }

    public function test_the_redemptions_show_who_used_it_and_whether_it_still_counts(): void
    {
        $this->signInAs('operations');
        $coupon = Coupon::factory()->create();
        $ledger = app(CouponLedger::class);
        $member = User::factory()->create();
        $live = $ledger->record($coupon, $member, Booking::factory()->create(), PricingPurpose::Booking, 100, 10);
        $given = $ledger->record($coupon, $member, Booking::factory()->create(['status' => BookingStatus::Cancelled]), PricingPurpose::Booking, 200, 20);

        $rows = collect($this->getJson("/api/v1/admin/coupons/{$coupon->id}/redemptions")->assertOk()->json('data'))->keyBy('id');

        $this->assertTrue($rows[$live->id]['counts']);
        $this->assertFalse($rows[$given->id]['counts']);
        $this->assertSame(20, $rows[$given->id]['points_discounted']);
        $this->assertSame($member->id, $rows[$live->id]['user']['id']);
        $this->assertSame($live->booking_id, $rows[$live->id]['booking_id']);
    }

    public function test_a_used_coupon_is_deactivated_not_deleted(): void
    {
        $this->signInAs('admin');
        $used = Coupon::factory()->create();
        app(CouponLedger::class)->record($used, User::factory()->create(), Booking::factory()->create(['status' => BookingStatus::Cancelled]), PricingPurpose::Booking, 100, 10);
        $unused = Coupon::factory()->create();

        $this->deleteJson("/api/v1/admin/coupons/{$used->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.coupon.has_redemptions'));
        $this->deleteJson("/api/v1/admin/coupons/{$unused->id}")->assertNoContent();

        $this->assertModelExists($used);
        $this->assertModelMissing($unused);
        $this->assertTrue(Activity::where('description', 'coupon_deleted')->where('subject_id', $unused->id)->exists());
    }

    public function test_operations_reads_but_does_not_write(): void
    {
        $this->signInAs('operations');
        $coupon = Coupon::factory()->create();

        $this->getJson('/api/v1/admin/coupons')->assertOk();
        $this->postJson('/api/v1/admin/coupons', $this->payload())->assertForbidden();
        $this->putJson("/api/v1/admin/coupons/{$coupon->id}", $this->payload())->assertForbidden();
        $this->deleteJson("/api/v1/admin/coupons/{$coupon->id}")->assertForbidden();
    }

    public function test_a_member_cannot_reach_coupons(): void
    {
        $this->signInAs('member');

        $this->getJson('/api/v1/admin/coupons')->assertForbidden();
    }

    public function test_coupon_permissions_are_derived(): void
    {
        $this->artisan('permissions:sync')->assertExitCode(0);

        foreach (['view', 'create', 'update', 'delete'] as $action) {
            $this->assertDatabaseHas('permissions', ['name' => "coupons.{$action}"]);
        }
    }
}
