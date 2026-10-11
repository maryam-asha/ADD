<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponLedger;
use App\Support\Enums\EventPaymentState;
use App\Support\Enums\EventRegistrationStatus;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A coupon given when paying for something already owed — an unpaid
 * booking, a closed co-working session, a registration — lowers what it
 * owes for good, and is filed on it
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 */
class CouponApplicationTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Space $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        $this->room = Space::factory()->room()->create(['hourly_rate_points' => 100]);
        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
        app(WalletService::class)->creditGeneral($wallet, 1000, WalletTransactionSource::TopUp);
        Sanctum::actingAs($this->member, ['*']);
    }

    private function unpaidBooking(array $attributes = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'space_id' => $this->room->id,
            'user_id' => $this->member->id,
            'status' => BookingStatus::Confirmed,
            'payment_state' => PaymentState::Unpaid,
            'price_points' => 100,
            'list_price_points' => 100,
            'amount_owed' => '10.00',
        ], $attributes));
    }

    private function pay(string $purpose, int $payableId, string $code, string $method = 'wallet'): TestResponse
    {
        $body = ['payment_method' => $method, 'coupon_code' => $code];

        return match ($purpose) {
            'booking' => $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', ['booking_id' => $payableId] + $body),
            'coworking_session' => $this->withHeader('lang', 'en')->postJson("/api/v1/member/coworking-sessions/walkin/{$payableId}/pay", $body),
            'event_registration' => $this->withHeader('lang', 'en')->postJson(
                '/api/v1/member/events/'.EventAttendee::findOrFail($payableId)->event_id.'/register',
                ['ticket_id' => $payableId] + $body,
            ),
        };
    }

    public function test_a_coupon_on_an_unpaid_booking_lowers_what_it_owes(): void
    {
        $coupon = Coupon::factory()->percentage(20)->create(['code' => 'LATE20']);
        $booking = $this->unpaidBooking();

        $this->pay('booking', $booking->id, 'late20')
            ->assertOk()
            ->assertJsonPath('coupon.code', 'LATE20')
            ->assertJsonPath('coupon.applied', true)
            ->assertJsonPath('coupon.points_discounted', 20);

        $booking->refresh();
        $this->assertSame(80, $booking->price_points);
        $this->assertSame(100, $booking->list_price_points);
        $this->assertSame('8.00', (string) $booking->amount_owed);
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(80, Payment::sole()->amount_points);
        $this->assertSame($coupon->id, app(CouponLedger::class)->couponFor($booking)->coupon_id);
    }

    public function test_a_second_coupon_on_the_same_booking_is_refused(): void
    {
        Coupon::factory()->create(['code' => 'ONE']);
        Coupon::factory()->create(['code' => 'TWO']);
        $booking = $this->unpaidBooking();
        app(CouponLedger::class)->record(Coupon::where('code', 'ONE')->sole(), $this->member, $booking, PricingPurpose::Booking, 100, 10);

        $this->pay('booking', $booking->id, 'TWO')
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.coupon.already_applied', [], 'en'));
    }

    /**
     * A card in flight would settle at the old amount, so no coupon is
     * applied — but that must not block paying: the payment goes ahead at the
     * current price and the answer says the code was not used.
     */
    public function test_a_booking_with_a_payment_under_way_takes_no_coupon_and_is_still_paid(): void
    {
        Coupon::factory()->create(['code' => 'WAIT']);
        $booking = $this->unpaidBooking();
        Payment::factory()->forPayable($booking)->create(['purpose' => PaymentPurpose::Booking, 'status' => PaymentStatus::Pending, 'user_id' => $this->member->id]);

        $this->pay('booking', $booking->id, 'WAIT')
            ->assertOk()
            ->assertJsonPath('coupon.applied', false)
            ->assertJsonPath('coupon.message', __('api.coupon.payment_in_progress', [], 'en'));

        $booking->refresh();
        $this->assertSame(100, $booking->price_points);
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertNull(app(CouponLedger::class)->couponFor($booking));
    }

    /**
     * A coupon that does not combine is not applied on a price an offer
     * already set — and the payment goes ahead at that price.
     */
    public function test_a_non_combinable_coupon_is_not_applied_on_an_offer_price_and_the_payment_goes_ahead(): void
    {
        Coupon::factory()->create(['code' => 'SOLO', 'combinable_with_offers' => false]);
        $booking = $this->unpaidBooking(['price_points' => 80, 'list_price_points' => 100]);

        $this->pay('booking', $booking->id, 'SOLO')
            ->assertOk()
            ->assertJsonPath('coupon.code', 'SOLO')
            ->assertJsonPath('coupon.applied', false)
            ->assertJsonPath('coupon.points_discounted', 0)
            ->assertJsonPath('coupon.message', __('api.coupon.offer_is_better', [], 'en'));

        $booking->refresh();
        $this->assertSame(80, $booking->price_points);
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(80, Payment::sole()->amount_points);
        $this->assertNull(app(CouponLedger::class)->couponFor($booking));
    }

    public function test_a_coupon_that_clears_a_session_leaves_nothing_to_pay(): void
    {
        Coupon::factory()->amountOff(50)->create(['code' => 'CLEAR']);
        $walkin = WalkinSession::factory()->create([
            'space_id' => $this->room->id,
            'user_id' => $this->member->id,
            'checked_in_at' => now()->subHour(),
            'checked_out_at' => now(),
            'payment_state' => PaymentState::Unpaid,
            'price_points' => 40,
            'list_price_points' => 40,
        ]);

        $this->pay('coworking_session', $walkin->id, 'CLEAR')
            ->assertCreated()
            ->assertJsonPath('data.payment', null)
            ->assertJsonPath('data.next', 'done')
            ->assertJsonPath('coupon.points_discounted', 40);

        $this->assertSame(0, $walkin->refresh()->price_points);
        $this->assertSame(0, Payment::count());
    }

    public function test_a_coupon_on_a_paid_event_registration(): void
    {
        Coupon::factory()->amountOff(10)->forEvents()->create(['code' => 'EVENT10']);
        $attendee = EventAttendee::factory()->create([
            'event_id' => Event::factory()->create()->id,
            'user_id' => $this->member->id,
            'status' => EventRegistrationStatus::Approved,
            'payment_state' => EventPaymentState::Unpaid,
            'price_points' => 50,
            'list_price_points' => 50,
        ]);

        $this->pay('event_registration', $attendee->id, 'EVENT10')->assertOk();

        $this->assertSame(40, $attendee->refresh()->price_points);
        $this->assertSame(40, Payment::sole()->amount_points);
    }
}
