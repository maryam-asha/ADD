<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET member/bookings/{id} carries what the booking screens draw — the
 * request, its status and stepper, the space, the day and time, the total,
 * whether it is paid, and what cancelling it would give back — and nothing
 * the screens do not (docs/decisions/booking-outcomes-and-extension.md).
 */
class MemberBookingDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Wallet $wallet;

    private Space $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        // A Monday, two hours before the 10:00 bookings below.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->utc());

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        $this->wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
        app(WalletService::class)->creditGeneral($this->wallet, 500, WalletTransactionSource::TopUp);
        Sanctum::actingAs($this->member, ['*']);

        $this->room = Space::factory()->room()->create([
            'name' => ['en' => 'Meeting Room A', 'ar' => 'غرفة اجتماعات أ'],
            'capacity' => 6,
            'hourly_rate_points' => 100,
            'slot_granularity_minutes' => 60,
        ]);
        BusinessHour::factory()->create([
            'branch_id' => $this->room->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 10:00–11:00 today, paid from $wallet (the member's own when null), through the real creation path. */
    private function bookPaid(?Wallet $wallet = null): Booking
    {
        return app(BookingCreationService::class)->create(
            $this->room,
            $this->member,
            Carbon::parse('2026-08-17 10:00', 'Asia/Damascus'),
            Carbon::parse('2026-08-17 11:00', 'Asia/Damascus'),
            $wallet?->owner_type,
            $wallet === null ? null : (int) $wallet->owner_id,
            null,
            'wallet',
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function bookUnpaid(array $attributes = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'space_id' => $this->room->id,
            'user_id' => $this->member->id,
            'start_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 11:00', 'Asia/Damascus')->utc(),
            'price_points' => 100,
        ], $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Booking $booking, string $lang = 'en'): array
    {
        return $this->withHeader('lang', $lang)
            ->getJson("/api/v1/member/bookings/{$booking->id}")
            ->assertOk()
            ->json('data');
    }

    public function test_the_detail_carries_what_the_booking_screens_show(): void
    {
        $detail = $this->detail($this->bookUnpaid());

        foreach (['id', 'reference', 'status', 'status_label', 'status_note', 'space', 'date_label', 'time_label', 'total', 'checked_in_at', 'checked_out_at', 'payment_state', 'payment_id', 'awaiting_payment', 'request_status', 'actions', 'cancellation'] as $key) {
            $this->assertArrayHasKey($key, $detail);
        }

        foreach (['when', 'breakdown', 'payment', 'note'] as $gone) {
            $this->assertArrayNotHasKey($gone, $detail);
        }

        $this->assertSame(['id', 'title', 'main_image', 'branch', 'capacity', 'hourly_price'], array_keys($detail['space']));
        $this->assertSame(['can_cancel', 'can_extend', 'extend_disabled_reason'], array_keys($detail['actions']));
        $this->assertSame('Meeting Room A', $detail['space']['title']);
        $this->assertSame(6, $detail['space']['capacity']);
        $this->assertSame('Today, August 17, 2026', $detail['date_label']);
        $this->assertSame('10:00 AM - 11:00 AM (1h)', $detail['time_label']);
    }

    /** The status stays confirmed until checkout; check-in and checkout are what tell a booking under way from one to come. */
    public function test_the_detail_says_when_the_member_checked_in_and_out(): void
    {
        $upcoming = $this->detail($this->bookUnpaid());
        $this->assertNull($upcoming['checked_in_at']);
        $this->assertNull($upcoming['checked_out_at']);

        $running = $this->detail($this->bookUnpaid([
            'start_at' => Carbon::parse('2026-08-17 07:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 09:00', 'Asia/Damascus')->utc(),
            'checked_in_at' => Carbon::parse('2026-08-17 07:05', 'Asia/Damascus')->utc(),
        ]));
        $this->assertSame('2026-08-17T07:05:00+03:00', $running['checked_in_at']);
        $this->assertNull($running['checked_out_at']);
    }

    public function test_every_booking_gets_its_own_number_in_a_yearly_series(): void
    {
        $first = $this->bookUnpaid();
        $second = $this->bookUnpaid(['start_at' => Carbon::parse('2026-08-17 12:00', 'Asia/Damascus')->utc(), 'end_at' => Carbon::parse('2026-08-17 13:00', 'Asia/Damascus')->utc()]);

        $this->assertSame('BK-2026-00001', $first->reference);
        $this->assertSame('BK-2026-00002', $second->reference);
        $this->assertSame('BK-2026-00002', $this->detail($second)['reference']);
    }

    public function test_the_total_is_what_the_booking_costs_in_the_members_currency(): void
    {
        $total = $this->detail($this->bookUnpaid())['total'];

        $this->assertSame(100, $total['points']);
        $this->assertIsString($total['price']);
    }

    public function test_the_payment_fields_say_whether_it_is_paid_and_which_payment_to_follow(): void
    {
        $booking = $this->bookPaid();

        $detail = $this->detail($booking);

        $this->assertSame('paid', $detail['payment_state']);
        $this->assertSame($booking->payments()->sole()->id, $detail['payment_id']);
        $this->assertFalse($detail['awaiting_payment']);
    }

    // ---------------------------------------------------------------
    // What cancelling would give back
    // ---------------------------------------------------------------

    public function test_a_paid_booking_says_what_cancelling_gives_back_and_where(): void
    {
        $cancellation = $this->detail($this->bookPaid())['cancellation'];

        $this->assertSame(100, $cancellation['refund_points']);
        $this->assertIsString($cancellation['refund_price']);
        $this->assertSame(__('api.booking.refund_to_own_wallet', [], 'en'), $cancellation['refund_to']);
        // The deadline is 09:00, an hour before the 10:00 start.
        $this->assertStringContainsString('09:00 AM', $cancellation['deadline_label']);
    }

    public function test_a_booking_a_company_paid_for_is_refunded_to_the_company(): void
    {
        $company = Company::factory()->create(['legal_name' => 'Acme Labs']);
        $this->member->companies()->attach($company->id);
        $companyWallet = Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        app(WalletService::class)->creditGeneral($companyWallet, 500, WalletTransactionSource::TopUp);

        $cancellation = $this->detail($this->bookPaid($companyWallet))['cancellation'];

        $this->assertSame('Acme Labs', $cancellation['refund_to']);
    }

    public function test_an_unpaid_booking_has_nothing_to_give_back(): void
    {
        $cancellation = $this->detail($this->bookUnpaid())['cancellation'];

        $this->assertSame(0, $cancellation['refund_points']);
        $this->assertNull($cancellation['refund_price']);
        $this->assertNull($cancellation['refund_to']);
    }

    public function test_a_booking_that_cannot_be_cancelled_has_no_cancellation_block(): void
    {
        $booking = $this->bookUnpaid(['status' => BookingStatus::Completed]);

        $this->assertNull($this->detail($booking)['cancellation']);
    }

    /** The quote and the refund are one definition: what the screen promised is what comes back. */
    public function test_the_refund_quoted_is_the_refund_made(): void
    {
        $booking = $this->bookPaid();
        $quoted = $this->detail($booking)['cancellation']['refund_points'];
        $before = app(WalletService::class)->generalBalance($this->wallet->fresh());

        app(BookingCancellationService::class)->cancel($booking);

        $this->assertSame($before + $quoted, app(WalletService::class)->generalBalance($this->wallet->fresh()));
    }
}
