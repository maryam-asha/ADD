<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Experience\Models\Event;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponLedger;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Apply" in the app: what a code would take off what the member is about
 * to buy — without buying it, and without spending the code
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 */
class CouponPreviewControllerTest extends TestCase
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

        Coupon::factory()->percentage(50)->create(['code' => 'HALF']);
    }

    private function preview(array $body): TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/coupons/preview', $body);
    }

    public function test_a_plan(): void
    {
        $plan = Plan::factory()->create(['price_points' => 120, 'is_active' => true, 'is_public' => true]);

        $this->preview(['code' => 'half', 'purpose' => 'plan_purchase', 'plan_id' => $plan->id])
            ->assertOk()
            ->assertJsonPath('data.code', 'HALF')
            ->assertJsonPath('data.applied', true)
            ->assertJsonPath('data.points_discounted', 60)
            ->assertJsonMissingPath('data.price_points')
            ->assertJsonMissingPath('data.lines');
    }

    /** The coupon reads as "my coupons" lists it: a flat amount off also in the display currency. */
    public function test_a_flat_amount_off_is_shown_in_the_display_currency(): void
    {
        Coupon::factory()->amountOff(30)->create(['code' => 'FLAT30']);

        $this->preview(['code' => 'FLAT30'])
            ->assertOk()
            ->assertJsonPath('data.discount_amount', ['amount' => '3', 'label' => '3 $', 'currency' => 'USD']);

        $this->preview(['code' => 'HALF'])
            ->assertOk()
            ->assertJsonPath('data.discount_amount', null);
    }

    public function test_a_booking_window(): void
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 100]);
        BusinessHour::factory()->create(['branch_id' => $space->building->branch_id, 'day_of_week' => DayOfWeek::Monday, 'open_time' => '08:00', 'close_time' => '20:00']);

        $this->preview([
            'code' => 'HALF',
            'purpose' => 'booking',
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T12:00:00+03:00',
        ])
            ->assertOk()
            ->assertJsonPath('data.points_discounted', 100);
    }

    /** A preview on something already owed changes nothing and spends nothing. */
    public function test_an_unpaid_booking_is_not_touched(): void
    {
        $booking = Booking::factory()->create([
            'user_id' => $this->member->id,
            'status' => BookingStatus::Confirmed,
            'payment_state' => PaymentState::Unpaid,
            'price_points' => 100,
            'list_price_points' => 100,
        ]);

        $this->preview(['code' => 'HALF', 'purpose' => 'booking', 'payable_id' => $booking->id])
            ->assertOk()
            ->assertJsonPath('data.points_discounted', 50);

        $this->assertSame(100, $booking->refresh()->price_points);
        $this->assertNull(app(CouponLedger::class)->couponFor($booking));
    }

    /** An event takes only a code targeted at events: HALF, untargeted, does not reach it. */
    public function test_an_event(): void
    {
        $event = Event::factory()->create(['is_free' => false, 'price_points' => 80]);
        Coupon::factory()->percentage(50)->forEvents()->create(['code' => 'EVENTHALF']);

        $this->preview(['code' => 'EVENTHALF', 'purpose' => 'event_registration', 'event_id' => $event->id])
            ->assertOk()
            ->assertJsonPath('data.points_discounted', 40);

        $this->preview(['code' => 'HALF', 'purpose' => 'event_registration', 'event_id' => $event->id])
            ->assertStatus(422)
            ->assertJsonMissingPath('reason')->assertJsonPath('message', __('api.coupon.not_applicable'));
    }

    public function test_a_code_that_cannot_be_used_says_why(): void
    {
        $plan = Plan::factory()->create(['price_points' => 120]);

        $this->preview(['code' => 'NOPE', 'purpose' => 'plan_purchase', 'plan_id' => $plan->id])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.coupon.not_found', [], 'en'));
    }

    /** Only a plan the member could buy is priced — as the purchase itself requires. */
    public function test_a_plan_that_cannot_be_bought_is_not_previewed(): void
    {
        foreach ([['is_public' => false], ['is_active' => false]] as $state) {
            $plan = Plan::factory()->create(['price_points' => 120, ...$state]);

            $this->preview(['code' => 'HALF', 'purpose' => 'plan_purchase', 'plan_id' => $plan->id])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['plan_id']);
        }
    }

    /** An item needs its purpose; a purpose alone is only checked, never priced. */
    public function test_an_item_needs_its_purpose(): void
    {
        $plan = Plan::factory()->create(['price_points' => 120, 'is_active' => true, 'is_public' => true]);

        $this->preview(['code' => 'HALF', 'plan_id' => $plan->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['purpose']);

        $this->preview(['code' => 'HALF', 'purpose' => 'plan_purchase'])
            ->assertOk()
            ->assertJsonPath('data.points_discounted', null);
    }

    /** The coupon's terms come back beside the price. */
    public function test_a_priced_preview_carries_the_coupon_terms(): void
    {
        $plan = Plan::factory()->create(['price_points' => 120, 'is_active' => true, 'is_public' => true]);

        $this->preview(['code' => 'HALF', 'purpose' => 'plan_purchase', 'plan_id' => $plan->id])
            ->assertOk()
            ->assertJsonMissingPath('message')
            ->assertJsonMissingPath('data.message')
            ->assertJsonPath('data.discount_type', 'percentage')
            ->assertJsonPath('data.discount_value', 50);
    }

    /** The payment methods screen weighs the wallet against the coupon price. */
    public function test_payment_methods_quote_the_coupon_price(): void
    {
        $plan = Plan::factory()->create(['price_points' => 120, 'is_active' => true, 'is_public' => true]);

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/payment-methods?purpose=plan_purchase&plan_id={$plan->id}&coupon_code=half")
            ->assertOk()
            ->assertJsonPath('data.amount_points', 60);
    }
}
