<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Pricing\Enums\DiscountKind;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\AppliedDiscount;
use App\Domain\Pricing\Models\Coupon;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "My coupons": the codes given to this member, by name or through their
 * company, that they could use now — and, asked about a space, a plan or an
 * event, only those that apply to it. A code given to no one in particular
 * stays private: it is never listed.
 */
class MemberCouponsTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
        Sanctum::actingAs($this->member, ['*']);
    }

    private function mine(array $query = []): TestResponse
    {
        return $this->withHeader('lang', 'en')->getJson('/api/v1/member/coupons?'.http_build_query($query));
    }

    private function given(array $attributes = []): Coupon
    {
        $coupon = Coupon::factory()->create($attributes);
        $coupon->users()->attach($this->member);

        return $coupon;
    }

    /** @return list<string> */
    private function codes(TestResponse $response): array
    {
        return collect($response->assertOk()->json('data'))->pluck('code')->sort()->values()->all();
    }

    public function test_lists_the_codes_given_to_the_member_or_their_company_only(): void
    {
        $this->given(['code' => 'MINE']);
        $company = Company::factory()->create();
        $company->members()->attach($this->member);
        Coupon::factory()->create(['code' => 'TEAM', 'company_id' => $company->id]);

        Coupon::factory()->create(['code' => 'PUBLIC']);
        Coupon::factory()->create(['code' => 'OTHERS'])->users()->attach(User::factory()->create());
        Coupon::factory()->create(['code' => 'OTHERCO', 'company_id' => Company::factory()->create()->id]);
        Coupon::factory()->automatic()->create()->users()->attach($this->member);

        $this->assertSame(['MINE', 'TEAM'], $this->codes($this->mine()));
    }

    public function test_only_codes_usable_now(): void
    {
        $this->given(['code' => 'NOW', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        $this->given(['code' => 'LATER', 'starts_at' => now()->addDay()]);
        $this->given(['code' => 'ENDED', 'ends_at' => now()]);
        $this->given(['code' => 'OFF', 'is_active' => false]);

        $this->assertSame(['NOW'], $this->codes($this->mine()));
    }

    public function test_leaves_out_a_code_the_member_has_used_up(): void
    {
        $spent = $this->given(['code' => 'ONCE', 'per_user_limit' => 1]);
        $this->given(['code' => 'TWICE', 'per_user_limit' => 2]);
        $booking = Booking::factory()->create(['user_id' => $this->member->id, 'status' => BookingStatus::Confirmed]);
        AppliedDiscount::create([
            'kind' => DiscountKind::Coupon,
            'coupon_id' => $spent->id,
            'user_id' => $this->member->id,
            'purpose' => PricingPurpose::Booking,
            'booking_id' => $booking->id,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'base_points' => 100,
            'points' => 10,
        ]);

        $response = $this->mine();

        $this->assertSame(['TWICE'], $this->codes($response));
        $response->assertJsonPath('data.0.uses_left', 2);
    }

    public function test_asked_about_a_space_lists_only_what_applies_to_it(): void
    {
        $room = Space::factory()->room()->create();
        $desk = Space::factory()->create();

        $this->given(['code' => 'ANYTHING']);
        $this->given(['code' => 'THISROOM'])->targets()->create(['purpose' => 'booking', 'space_id' => $room->id]);
        $this->given(['code' => 'ROOMS'])->targets()->create(['purpose' => 'booking', 'space_type' => 'room']);
        $this->given(['code' => 'DESK'])->targets()->create(['purpose' => 'booking', 'space_id' => $desk->id]);
        $this->given(['code' => 'SESSIONS'])->targets()->create(['purpose' => 'coworking_session', 'space_id' => $room->id]);

        $this->assertSame(['ANYTHING', 'ROOMS', 'THISROOM'], $this->codes($this->mine(['purpose' => 'booking', 'space_id' => $room->id])));
    }

    public function test_asked_about_a_plan_lists_only_what_applies_to_it(): void
    {
        $plan = Plan::factory()->create(['is_active' => true, 'is_public' => true]);
        $other = Plan::factory()->create(['is_active' => true, 'is_public' => true]);

        $this->given(['code' => 'THISPLAN'])->targets()->create(['purpose' => 'plan_purchase', 'plan_id' => $plan->id]);
        $this->given(['code' => 'ANYPLAN'])->targets()->create(['purpose' => 'plan_purchase']);
        $this->given(['code' => 'OTHERPLAN'])->targets()->create(['purpose' => 'plan_purchase', 'plan_id' => $other->id]);

        $this->assertSame(['ANYPLAN', 'THISPLAN'], $this->codes($this->mine(['purpose' => 'plan_purchase', 'plan_id' => $plan->id])));
    }

    public function test_shows_what_a_code_takes_off_and_where_it_applies(): void
    {
        $room = Space::factory()->room()->create();
        $coupon = $this->given(['code' => 'ROOM20', 'discount_value' => 20, 'max_discount_points' => 50, 'ends_at' => Carbon::parse('2026-08-30 21:00:00', 'UTC')]);
        $coupon->targets()->create(['purpose' => 'booking', 'space_id' => $room->id]);

        $this->mine()
            ->assertOk()
            ->assertJsonPath('data.0.code', 'ROOM20')
            ->assertJsonPath('data.0.name', 'Ten percent off')
            ->assertJsonPath('data.0.discount_type', 'percentage')
            ->assertJsonPath('data.0.discount_value', 20)
            ->assertJsonPath('data.0.max_discount_points', 50)
            ->assertJsonPath('data.0.ends_at', '2026-08-30T21:00:00.000000Z')
            ->assertJsonPath('data.0.uses_left', null)
            ->assertJsonPath('data.0.targets.0.purpose', 'booking')
            ->assertJsonPath('data.0.targets.0.space_id', $room->id);
    }

    public function test_an_item_needs_its_purpose(): void
    {
        $this->mine(['space_id' => Space::factory()->create()->id])->assertUnprocessable()->assertJsonValidationErrors('purpose');
        $this->mine(['purpose' => 'plan_purchase', 'space_id' => Space::factory()->create()->id])->assertUnprocessable()->assertJsonValidationErrors('space_id');
    }
}
