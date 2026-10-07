<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Pricing\Models\Coupon;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A booking is made first, then paid. To pay it another way — after a card
 * failed, or later — the app sends the same create request again with the
 * booking's id: nothing is created, no creation rule runs, only how it is
 * paid changes (docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md).
 */
class BookingPayByIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-api-key',
            'paymera.terminal_id' => '99990001',
            'paymera.currency' => 'USD',
            'paymera.currency_exponent' => 2,
            'paymera.default_lang' => 'en',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(int $points = 0): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    private function openSpace(): Space
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 100, 'slot_granularity_minutes' => 60]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    private function confirmScreen(Space $space, array $extra = []): array
    {
        return array_merge([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
        ], $extra);
    }

    private function book(array $body)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', $body);
    }

    /**
     * The reproduced bug: card unreachable, back on the confirm screen,
     * confirm again with cash — refused before, because the member's own
     * unpaid booking held the slot.
     */
    public function test_after_a_card_that_could_not_open_the_same_screen_pays_the_same_booking_by_cash(): void
    {
        $this->member();
        $space = $this->openSpace();
        Http::fake(['*' => Http::response('', 500)]);

        $first = $this->book($this->confirmScreen($space, ['payment_method' => 'paymera']))
            ->assertCreated()
            ->assertJsonPath('payment', null);

        $this->book($this->confirmScreen($space, ['payment_method' => 'cash', 'booking_id' => $first->json('data.id')]))
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('next', 'pay_at_reception')
            ->assertJsonPath('payment', null);

        $this->assertSame(1, Booking::count());
    }

    public function test_sent_again_with_the_wallet_pays_it_now(): void
    {
        $member = $this->member(300);
        $space = $this->openSpace();
        Http::fake(['*' => Http::response('', 500)]);
        $id = $this->book($this->confirmScreen($space, ['payment_method' => 'paymera']))->json('data.id');

        $this->book($this->confirmScreen($space, ['payment_method' => 'wallet', 'booking_id' => $id]))
            ->assertOk()
            ->assertJsonPath('next', 'done')
            ->assertJsonPath('payment.status', 'accepted');

        $this->assertSame(PaymentState::Paid, Booking::findOrFail($id)->payment_state);
        $this->assertSame(200, app(WalletService::class)->generalBalance(
            Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->firstOrFail()
        ));
    }

    public function test_sent_again_with_the_card_opens_a_new_card_payment_for_the_same_booking(): void
    {
        $this->member();
        $space = $this->openSpace();
        $id = Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => auth()->id(),
            'start_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 11:00', 'Asia/Damascus')->utc(),
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => null,
            'price_points' => 100,
        ])->id;
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-9/en', 'paymentId' => 'pay-9'],
            ]),
        ]);

        $this->book(['booking_id' => $id, 'payment_method' => 'paymera'])
            ->assertOk()
            ->assertJsonPath('next', 'redirect')
            ->assertJsonPath('redirect_url', 'https://egate-t.paymera.test/start/pay-9/en');

        $this->assertSame($id, Payment::sole()->payable_id);
    }

    /**
     * Sent again with the id, no creation rule runs: a booking made at
     * 08:00 for 10:00, paid again at 09:30 — inside the 60-minute notice
     * a new booking needs — is not refused as too soon. Made with cash: an
     * ordinary unpaid booking with no deadline (one held for a card would
     * have expired by 09:30, and rightly takes no new payment).
     */
    public function test_sent_again_close_to_the_start_is_not_refused_as_too_soon(): void
    {
        $this->member(300);
        $space = $this->openSpace();
        $id = $this->book($this->confirmScreen($space, ['payment_method' => 'cash']))->json('data.id');

        Carbon::setTestNow(Carbon::parse('2026-08-17 09:30:00', 'Asia/Damascus'));

        $this->book($this->confirmScreen($space, ['payment_method' => 'wallet', 'booking_id' => $id]))
            ->assertOk()
            ->assertJsonPath('next', 'done');
    }

    /**
     * A code that creation did not apply (it does not combine with the sale
     * the room is on) must not block paying later: the confirm screen sends
     * it again with the id, and the payment goes ahead at the current price,
     * the answer saying the code was not used.
     */
    public function test_a_code_that_creation_did_not_apply_does_not_block_paying_by_another_method(): void
    {
        $this->member();
        $space = $this->openSpace();
        $space->update([
            'sale_hourly_rate_points' => 80,
            'sale_starts_at' => Carbon::parse('2026-08-17 00:00:00', 'Asia/Damascus')->utc(),
            'sale_ends_at' => Carbon::parse('2026-08-18 00:00:00', 'Asia/Damascus')->utc(),
        ]);
        Coupon::factory()->amountOff(10)->create(['code' => 'SOLO', 'combinable_with_offers' => false]);
        Http::fake(['*' => Http::response('', 500)]);

        $first = $this->book($this->confirmScreen($space, ['payment_method' => 'paymera', 'coupon_code' => 'SOLO']))
            ->assertCreated()
            ->assertJsonPath('coupon.applied', false);
        $this->assertSame(80, Booking::findOrFail($first->json('data.id'))->price_points);

        $this->book($this->confirmScreen($space, [
            'payment_method' => 'cash',
            'booking_id' => $first->json('data.id'),
            'coupon_code' => 'SOLO',
        ]))
            ->assertOk()
            ->assertJsonPath('coupon.code', 'SOLO')
            ->assertJsonPath('coupon.applied', false)
            ->assertJsonPath('coupon.points_discounted', 0)
            ->assertJsonPath('coupon.message', __('api.coupon.offer_is_better', [], 'en'))
            ->assertJsonPath('next', 'pay_at_reception');

        $this->assertSame(1, Booking::count());
    }

    public function test_a_code_sent_while_a_card_is_pending_does_not_block_paying_by_the_wallet(): void
    {
        $member = $this->member(300);
        $space = $this->openSpace();
        Coupon::factory()->create(['code' => 'WAIT']);
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 11:00', 'Asia/Damascus')->utc(),
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => null,
            'price_points' => 100,
        ]);
        Payment::factory()->forPayable($booking)->create([
            'purpose' => PaymentPurpose::Booking,
            'status' => PaymentStatus::Pending,
            'user_id' => $member->id,
        ]);

        $this->book(['booking_id' => $booking->id, 'payment_method' => 'wallet', 'coupon_code' => 'WAIT'])
            ->assertOk()
            ->assertJsonPath('coupon.applied', false)
            ->assertJsonPath('coupon.message', __('api.coupon.payment_in_progress', [], 'en'))
            ->assertJsonPath('next', 'done');

        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);
        $this->assertSame(100, $booking->price_points);
        $this->assertSame(200, app(WalletService::class)->generalBalance(
            Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->firstOrFail()
        ));
    }

    /** A code that really cannot be used (unknown) still refuses the payment. */
    public function test_an_unknown_code_sent_with_the_id_still_refuses(): void
    {
        $this->member(300);
        $space = $this->openSpace();
        Http::fake(['*' => Http::response('', 500)]);
        $id = $this->book($this->confirmScreen($space, ['payment_method' => 'paymera']))->json('data.id');

        $this->book(['booking_id' => $id, 'payment_method' => 'wallet', 'coupon_code' => 'NOPE'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.coupon.not_found', [], 'en'));
    }

    public function test_explicit_nulls_beside_the_booking_id_are_accepted(): void
    {
        $this->member();
        $space = $this->openSpace();
        Http::fake(['*' => Http::response('', 500)]);
        $id = $this->book($this->confirmScreen($space, ['payment_method' => 'paymera']))->json('data.id');

        $this->book([
            'booking_id' => $id,
            'space_id' => null,
            'start_at' => null,
            'end_at' => null,
            'payment_method' => 'cash',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('next', 'pay_at_reception');
    }

    public function test_creating_without_a_booking_id_still_needs_the_space_and_the_times(): void
    {
        $this->member();
        $space = $this->openSpace();

        $this->book($this->confirmScreen($space, ['space_id' => null, 'payment_method' => 'cash']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('space_id');
        $this->book($this->confirmScreen($space, ['start_at' => null, 'payment_method' => 'cash']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_at');
        $this->book($this->confirmScreen($space, ['end_at' => null, 'payment_method' => 'cash']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_at');
    }

    public function test_values_that_differ_from_the_booking_are_refused(): void
    {
        $this->member();
        $space = $this->openSpace();
        Http::fake(['*' => Http::response('', 500)]);
        $id = $this->book($this->confirmScreen($space, ['payment_method' => 'paymera']))->json('data.id');

        $this->book($this->confirmScreen($space, [
            'payment_method' => 'cash',
            'booking_id' => $id,
            'end_at' => '2026-08-17T12:00:00+03:00',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.booking.booking_mismatch', [], 'en'));
    }

    public function test_another_members_booking_is_not_found(): void
    {
        $this->member();
        $someoneElse = Booking::factory()->create(['payment_state' => PaymentState::Unpaid, 'price_points' => 100]);

        $this->book(['booking_id' => $someoneElse->id, 'payment_method' => 'cash'])->assertNotFound();
    }

    public function test_a_paid_booking_is_not_paid_again(): void
    {
        $this->member(300);
        $space = $this->openSpace();
        $id = $this->book($this->confirmScreen($space, ['payment_method' => 'wallet']))->json('data.id');

        $this->book(['booking_id' => $id, 'payment_method' => 'wallet'])
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.payment.already_paid', [], 'en'));
    }

    public function test_a_cancelled_booking_cannot_be_paid(): void
    {
        $this->member(300);
        $cancelled = Booking::factory()->create([
            'user_id' => auth()->id(),
            'status' => BookingStatus::Cancelled,
            'payment_state' => PaymentState::Unpaid,
            'price_points' => 100,
        ]);

        $this->book(['booking_id' => $cancelled->id, 'payment_method' => 'wallet'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.payment.booking_not_payable', [], 'en'));
    }
}
