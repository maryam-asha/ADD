<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingApprovalService;
use App\Domain\Booking\Services\BookingPaymentLine;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Notification\Concerns\ReadsNotices;
use Tests\TestCase;

/**
 * A booking that is confirmed tells its member so, and how it was or will
 * be paid: points from their wallet or their company's, by card, cash at
 * the desk (paid or to pay), covered by the plan, or still owed. A batch is
 * one notice; one the desk made says the team booked it; an approval
 * carries the same payment line.
 */
class BookingConfirmedNoticeTest extends TestCase
{
    use ReadsNotices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));
        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-api-key',
            'paymera.terminal_id' => '99990001',
            'paymera.currency' => 'USD',
            'paymera.default_lang' => 'en',
        ]);
        // The card page cannot open: a card booking is still made, held.
        Http::fake(['*' => Http::response('', 500)]);
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

        return $member;
    }

    private function actingAsMember(int $points = 0): User
    {
        $member = $this->member($points);
        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    /** 100 points an hour, open every day 08:00–20:00. */
    private function room(array $attributes = []): Space
    {
        $space = Space::factory()->room()->create([
            'hourly_rate_points' => 100,
            'slot_granularity_minutes' => 60,
        ] + $attributes);

        foreach (DayOfWeek::cases() as $day) {
            BusinessHour::factory()->create([
                'branch_id' => $space->building->branch_id,
                'day_of_week' => $day,
                'open_time' => '08:00',
                'close_time' => '20:00',
            ]);
        }

        return $space;
    }

    /** @return array<string, mixed> */
    private function slot(Space $space, string $date = '2026-08-17'): array
    {
        return [
            'space_id' => $space->id,
            'start_at' => "{$date}T10:00:00+03:00",
            'end_at' => "{$date}T11:00:00+03:00",
        ];
    }

    private function book(Space $space, string $method)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', $this->slot($space) + ['payment_method' => $method]);
    }

    private function body(User $member, string $templateKey, string $locale = 'en'): string
    {
        return $this->noticeText($this->assertNoticed($member, $templateKey), $locale)['body'];
    }

    public function test_a_wallet_booking_says_what_was_taken_from_the_wallet(): void
    {
        $member = $this->actingAsMember(500);

        $this->book($this->room(), 'wallet')->assertCreated();

        $notice = $this->assertNoticed($member, 'booking.confirmed');
        $this->assertSame(
            'Your booking for '.Space::sole()->translate('name', 'en').' on 2026-08-17 at 10:00 is confirmed. 100 points were taken from your wallet.',
            $this->noticeText($notice, 'en')['body'],
        );
        $this->assertStringEndsWith('اقتُطع 100 نقطة من محفظتك.', $this->noticeText($notice, 'ar')['body']);
        $this->assertSame('تم تأكيد حجزك', $this->noticeText($notice, 'ar')['title']);
    }

    public function test_a_cash_booking_says_it_is_paid_at_reception(): void
    {
        $member = $this->actingAsMember();

        $this->book($this->room(), 'cash')->assertCreated();

        $this->assertStringEndsWith('is confirmed. The amount (100 points) is paid at reception.', $this->body($member, 'booking.confirmed'));
    }

    public function test_a_card_booking_is_told_once_when_its_card_goes_through(): void
    {
        $member = $this->actingAsMember();

        $this->book($this->room(), 'paymera')->assertCreated();
        $booking = Booking::sole();
        $this->assertSame(BookingStatus::AwaitingPayment, $booking->status);
        $this->assertNotNoticed($member, 'booking.confirmed');

        $payment = Payment::factory()->accepted()->forPayable($booking)->create([
            'purpose' => PaymentPurpose::Booking,
            'user_id' => $member->id,
            'wallet_id' => Wallet::query()->where('owner_type', OwnerType::User)->where('owner_id', $member->id)->value('id'),
            'points_to_credit' => 100,
        ]);
        app(PaymentService::class)->fulfilAcceptedGatewayPayment($payment);

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertStringEndsWith('is confirmed. 100 points were paid by card.', $this->body($member, 'booking.confirmed'));
        $this->assertNotNoticed($member, 'payment.received');
    }

    public function test_a_booking_awaiting_approval_is_not_confirmed_yet_and_its_approval_says_how_it_was_paid(): void
    {
        $member = $this->actingAsMember(500);
        $this->book($this->room(['requires_approval' => true]), 'wallet')->assertCreated();

        $this->assertNotNoticed($member, 'booking.confirmed');

        $operator = User::factory()->create();
        $operator->assignRole('admin');
        app(BookingApprovalService::class)->approve(Booking::sole(), $operator);

        $this->assertStringEndsWith('is confirmed. 100 points were taken from your wallet.', $this->body($member, 'booking.approved_with_payment'));
        $this->assertNotNoticed($member, 'booking.approved');
    }

    public function test_a_batch_is_one_notice_with_its_dates_and_total(): void
    {
        $member = $this->actingAsMember(500);
        $space = $this->room();

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'wallet',
            'windows' => [
                $this->slot($space, '2026-08-17'),
                $this->slot($space, '2026-08-18'),
                $this->slot($space, '2026-08-19'),
            ],
        ])->assertCreated();

        $this->assertNotNoticed($member, 'booking.confirmed');
        $notice = $this->assertNoticed($member, 'booking.confirmed_batch');
        $name = $space->translate('name', 'en');
        $this->assertSame(
            "3 bookings for {$name} are confirmed (2026-08-17, 2026-08-18, 2026-08-19). 300 points were taken from your wallet.",
            $this->noticeText($notice, 'en')['body'],
        );
        $this->assertStringContainsString('(2026-08-17، 2026-08-18، 2026-08-19)', $this->noticeText($notice, 'ar')['body']);
    }

    public function test_a_booking_the_desk_made_says_the_team_booked_it_and_the_cash_it_took(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('admin');
        Sanctum::actingAs($operator, ['*']);
        $member = $this->member();
        $space = $this->room();

        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/bookings', [
            'user_id' => $member->id,
            'space_id' => $space->id,
            'start_at' => '2026-08-17 14:00',
            'end_at' => '2026-08-17 15:00',
            'payment_method' => 'cash',
            'cash_collected' => true,
        ])->assertCreated();

        $notice = $this->assertNoticed($member, 'booking.booked_for_you');
        $this->assertSame('The ADD team booked for you', $this->noticeText($notice, 'en')['title']);
        $this->assertStringEndsWith('You paid 100 points in cash at reception.', $this->noticeText($notice, 'en')['body']);
        $this->assertNotNoticed($member, 'booking.confirmed');
        $this->assertNotNoticed($member, 'payment.received');
    }

    public function test_the_company_wallet_is_named(): void
    {
        $member = $this->member();
        $company = Company::factory()->create(['legal_name' => 'Acme']);
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        $booking = Booking::factory()->create(['user_id' => $member->id, 'price_points' => 100]);
        Payment::factory()->withoutGateway('wallet', 100)->forPayable($booking)->create([
            'purpose' => PaymentPurpose::Booking,
            'status' => PaymentStatus::Accepted,
            'settled_at' => now(),
            'user_id' => $member->id,
            'wallet_id' => $wallet->id,
        ]);

        $this->assertSame(
            ['ar' => ' اقتُطع 100 نقطة من محفظة Acme.', 'en' => ' 100 points were taken from the Acme wallet.'],
            app(BookingPaymentLine::class)->for([$booking]),
        );
    }

    public function test_a_plan_covered_booking_says_so_a_free_one_says_nothing_and_an_unpaid_one_is_owed(): void
    {
        $lines = app(BookingPaymentLine::class);

        $covered = Booking::factory()->create(['price_points' => 0, 'payment_state' => PaymentState::Paid, 'payment_source' => PaymentSource::Plan]);
        $this->assertSame(' The booking is fully covered by your plan hours.', $lines->for([$covered])['en']);

        $free = Booking::factory()->create(['price_points' => 0]);
        $this->assertSame(['ar' => '', 'en' => ''], $lines->for([$free]));

        $owed = Booking::factory()->create(['price_points' => 150, 'chosen_payment_method' => null]);
        $this->assertSame(' 150 points are due; pay them in the app or at reception.', $lines->for([$owed])['en']);
    }
}
