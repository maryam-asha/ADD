<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Models\ExchangeRate;
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
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
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

    public function test_asked_about_a_purpose_alone_lists_every_code_for_it_whatever_item_it_names(): void
    {
        $room = Space::factory()->room()->create();
        $this->given(['code' => 'ANYTHING']);
        $this->given(['code' => 'THISROOM'])->targets()->create(['purpose' => 'booking', 'space_id' => $room->id]);
        $this->given(['code' => 'ROOMS'])->targets()->create(['purpose' => 'booking', 'space_type' => 'room']);
        $this->given(['code' => 'SESSIONS'])->targets()->create(['purpose' => 'coworking_session', 'space_id' => $room->id]);
        $this->given(['code' => 'PLANS'])->targets()->create(['purpose' => 'plan_purchase']);

        $this->assertSame(['ANYTHING', 'ROOMS', 'THISROOM'], $this->codes($this->mine(['purpose' => 'booking'])));
    }

    /** @return array<string, bool> code => can_apply */
    private function canApply(TestResponse $response): array
    {
        return collect($response->assertOk()->json('data'))->pluck('can_apply', 'code')->sortKeys()->all();
    }

    public function test_asked_about_a_space_lists_every_code_for_the_purpose_saying_which_apply_to_it(): void
    {
        $room = Space::factory()->room()->create();
        $desk = Space::factory()->create();

        $this->given(['code' => 'ANYTHING']);
        $this->given(['code' => 'THISROOM'])->targets()->create(['purpose' => 'booking', 'space_id' => $room->id]);
        $this->given(['code' => 'ROOMS'])->targets()->create(['purpose' => 'booking', 'space_type' => 'room']);
        $this->given(['code' => 'DESK'])->targets()->create(['purpose' => 'booking', 'space_id' => $desk->id]);
        $this->given(['code' => 'HALLS'])->targets()->create(['purpose' => 'booking', 'space_type' => 'event_hall']);
        $this->given(['code' => 'SESSIONS'])->targets()->create(['purpose' => 'coworking_session', 'space_id' => $room->id]);

        $this->assertSame(
            ['ANYTHING' => true, 'DESK' => false, 'HALLS' => false, 'ROOMS' => true, 'THISROOM' => true],
            $this->canApply($this->mine(['purpose' => 'booking', 'space_id' => $room->id])),
        );
    }

    public function test_asked_about_a_plan_lists_every_code_for_the_purpose_saying_which_apply_to_it(): void
    {
        $plan = Plan::factory()->create(['is_active' => true, 'is_public' => true]);
        $other = Plan::factory()->create(['is_active' => true, 'is_public' => true]);

        $this->given(['code' => 'THISPLAN'])->targets()->create(['purpose' => 'plan_purchase', 'plan_id' => $plan->id]);
        $this->given(['code' => 'ANYPLAN'])->targets()->create(['purpose' => 'plan_purchase']);
        $this->given(['code' => 'OTHERPLAN'])->targets()->create(['purpose' => 'plan_purchase', 'plan_id' => $other->id]);

        $this->assertSame(
            ['ANYPLAN' => true, 'OTHERPLAN' => false, 'THISPLAN' => true],
            $this->canApply($this->mine(['purpose' => 'plan_purchase', 'plan_id' => $plan->id])),
        );
    }

    /** An event lists only codes targeted at events: one for everything stops short of them. */
    public function test_asked_about_an_event_lists_only_codes_targeted_at_events(): void
    {
        $event = Event::factory()->create(['is_free' => false, 'price_points' => 80]);

        $this->given(['code' => 'ANYTHING']);
        $this->given(['code' => 'EVENTS'])->targets()->create(['purpose' => 'event_registration']);
        $this->given(['code' => 'THISEVENT'])->targets()->create(['purpose' => 'event_registration', 'event_id' => $event->id]);
        $this->given(['code' => 'OTHEREVENT'])->targets()->create(['purpose' => 'event_registration', 'event_id' => Event::factory()->create()->id]);

        $this->assertSame(
            ['EVENTS' => true, 'OTHEREVENT' => false, 'THISEVENT' => true],
            $this->canApply($this->mine(['purpose' => 'event_registration', 'event_id' => $event->id])),
        );
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
            ->assertJsonMissingPath('data.0.targets');
    }

    public function test_a_flat_amount_off_is_also_shown_in_the_chosen_currency(): void
    {
        app(SettingService::class)->setDefault('point.rate_usd', '0.10', SettingValueType::String);
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0001']);
        $this->given(['code' => 'FLAT30', 'discount_type' => 'amount_off', 'discount_value' => 30]);
        $this->given(['code' => 'TENPCT', 'discount_type' => 'percentage', 'discount_value' => 10]);

        $usd = collect($this->mine()->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(['amount' => '3', 'label' => '3 $', 'currency' => 'USD'], $usd['FLAT30']['discount_amount']);
        $this->assertNull($usd['TENPCT']['discount_amount']);

        $syp = collect($this->withHeader('currency', 'SYP')->mine()->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(['amount' => '30000', 'label' => '30,000 SYP', 'currency' => 'SYP'], $syp['FLAT30']['discount_amount']);
    }

    public function test_an_item_needs_its_purpose(): void
    {
        $this->mine(['space_id' => Space::factory()->create()->id])->assertUnprocessable()->assertJsonValidationErrors('purpose');
        $this->mine(['purpose' => 'plan_purchase', 'space_id' => Space::factory()->create()->id])->assertUnprocessable()->assertJsonValidationErrors('space_id');
    }

    /** @return array<string, mixed>|null the `with_offer` of one code in a response */
    private function besideOffer(TestResponse $response, string $code): ?array
    {
        $coupon = collect($response->assertOk()->json('data'))->firstWhere('code', $code);
        $this->assertNotNull($coupon, "{$code} is not listed");
        $this->assertArrayHasKey('with_offer', $coupon);

        return $coupon['with_offer'];
    }

    /** Asked about nothing, there is no offer to sit beside: the key is there, null. */
    public function test_with_no_item_with_offer_is_null(): void
    {
        $this->given(['code' => 'ANY']);

        $this->assertNull($this->besideOffer($this->mine(), 'ANY'));
    }

    /** A space's price depends on the slot, so it says only whether the code stacks on the offer. */
    public function test_on_a_space_with_an_offer_it_says_whether_the_code_stacks(): void
    {
        $room = Space::factory()->room()->create(['hourly_rate_points' => 100]);
        Coupon::factory()->automatic()->percentage(20)->create(['name' => ['en' => 'Rooms week', 'ar' => 'أسبوع الغرف']])
            ->targets()->create(['purpose' => 'booking', 'space_type' => 'room']);
        $this->given(['code' => 'STACK', 'combinable_with_offers' => true]);
        $this->given(['code' => 'ALONE', 'combinable_with_offers' => false]);

        $response = $this->mine(['purpose' => 'booking', 'space_id' => $room->id]);

        $stack = $this->besideOffer($response, 'STACK');
        $this->assertSame(['name' => 'Rooms week', 'original_points' => 100, 'points' => 80], $stack['offer']);
        $this->assertTrue($stack['combines']);
        $this->assertNull($stack['price_points']);
        $this->assertNull($stack['applied']);
        $this->assertSame(__('api.coupon.with_offer.stacks', [], 'en'), $stack['note']);

        $alone = $this->besideOffer($response, 'ALONE');
        $this->assertFalse($alone['combines']);
        $this->assertSame(__('api.coupon.with_offer.better_of_two', [], 'en'), $alone['note']);
    }

    public function test_on_a_space_with_no_offer_there_is_nothing_to_say(): void
    {
        $room = Space::factory()->room()->create(['hourly_rate_points' => 100]);
        $this->given(['code' => 'ANY']);

        $beside = $this->besideOffer($this->mine(['purpose' => 'booking', 'space_id' => $room->id]), 'ANY');

        $this->assertNull($beside['offer']);
        $this->assertNull($beside['note']);
    }

    /** A plan has one price: each code is quoted as the purchase would quote it. */
    public function test_on_a_plan_each_code_is_quoted_against_the_offer(): void
    {
        $plan = Plan::factory()->create(['price_points' => 120, 'is_active' => true, 'is_public' => true]);
        Coupon::factory()->automatic()->amountOff(20)->create()
            ->targets()->create(['purpose' => 'plan_purchase', 'plan_id' => $plan->id]);
        $this->given(['code' => 'SMALL', 'discount_value' => 10, 'combinable_with_offers' => false]);
        $this->given(['code' => 'BIG', 'discount_value' => 50, 'combinable_with_offers' => false]);
        $this->given(['code' => 'ONTOP', 'discount_value' => 10, 'combinable_with_offers' => true]);

        $response = $this->mine(['purpose' => 'plan_purchase', 'plan_id' => $plan->id]);

        // 10% of 120 leaves 108, above the offer's 100: the purchase would refuse it.
        $this->assertFalse($this->canApply($response)['SMALL']);
        $this->assertNull($this->besideOffer($response, 'SMALL'));

        // 50% of 120 leaves 60: the code beats the offer.
        $big = $this->besideOffer($response, 'BIG');
        $this->assertTrue($big['applied']);
        $this->assertSame(60, $big['price_points']);
        $this->assertSame(__('api.coupon.with_offer.code_is_better', [], 'en'), $big['note']);

        // 10% on top of the offer's 100.
        $onTop = $this->besideOffer($response, 'ONTOP');
        $this->assertTrue($onTop['applied']);
        $this->assertSame(90, $onTop['price_points']);
        $this->assertSame(__('api.coupon.with_offer.stacks', [], 'en'), $onTop['note']);
    }

    /** Below a code's minimum price it could not be used here: listed, but it cannot apply. */
    public function test_on_a_plan_below_a_codes_minimum_the_code_cannot_apply(): void
    {
        $plan = Plan::factory()->create(['price_points' => 120, 'is_active' => true, 'is_public' => true]);
        $this->given(['code' => 'BIGSPEND', 'min_amount_points' => 500]);
        $this->given(['code' => 'ANY']);

        $response = $this->mine(['purpose' => 'plan_purchase', 'plan_id' => $plan->id]);

        $this->assertSame(['ANY' => true, 'BIGSPEND' => false], $this->canApply($response));
        $this->assertNull($this->besideOffer($response, 'BIGSPEND'));
    }

    public function test_on_an_event_the_offer_targeted_at_events_is_quoted(): void
    {
        $event = Event::factory()->create(['is_free' => false, 'price_points' => 80]);
        Coupon::factory()->automatic()->percentage(25)->forEvents()->create();
        $this->given(['code' => 'EVENTS', 'combinable_with_offers' => true])->targets()->create(['purpose' => 'event_registration']);

        $beside = $this->besideOffer($this->mine(['purpose' => 'event_registration', 'event_id' => $event->id]), 'EVENTS');

        $this->assertSame(60, $beside['offer']['points']);
        // 10% on top of the offer's 60.
        $this->assertSame(54, $beside['price_points']);
        $this->assertTrue($beside['applied']);
    }
}
