<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Pricing\Models\Coupon;
use Carbon\Carbon;
use Database\Factories\CouponFactory;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Pricing\Concerns\MakesOffers;
use Tests\TestCase;

/**
 * A booking paid by card is charged exactly what the app showed for it:
 * the preview's total, for the time booked, after any offer and coupon —
 * the same points on the booking, on the payment and, converted, in the
 * amount sent to Paymera. A point is 0.10 USD and 1 USD is 10,000 SYP, so
 * every point is 1,000 SYP. 2026-08-17 is a Monday.
 */
class BookingCardChargeMatchesPreviewTest extends TestCase
{
    use MakesOffers, RefreshDatabase;

    private const SYP_PER_POINT = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-key',
            'paymera.terminal_id' => '99990001',
            'paymera.currency' => 'SYP',
            'paymera.default_lang' => 'en',
        ]);

        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0001']);

        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-1/en', 'paymentId' => 'pay-1'],
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array<string, array{int, int, ?string, int}>
     */
    public static function bookings(): array
    {
        // [hourly rate, minutes booked, discount, points due]
        return [
            'an hour' => [100, 60, null, 100],
            'an hour and a half' => [100, 90, null, 150],
            'two and a half hours' => [100, 150, null, 250],
            // 2.5 h × 45 = 112.5: a partial point costs a whole one.
            'a partial point rounds up' => [45, 150, null, 113],
            'a percentage coupon' => [100, 90, 'coupon:20%', 120],
            'an amount-off coupon' => [100, 90, 'coupon:30pts', 120],
            'an offer' => [100, 120, 'offer', 120],
            'an offer and a coupon that stacks on it' => [100, 120, 'offer+coupon:10%', 108],
            // Not combinable: 20% off the list price is 160, above the offer's 120, so the offer stays.
            'an offer beats a coupon that does not stack' => [100, 120, 'offer+coupon:20%-alone', 120],
        ];
    }

    #[DataProvider('bookings')]
    public function test_the_card_is_charged_what_the_app_showed(int $rate, int $minutes, ?string $discount, int $points): void
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => $rate]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        $code = $this->discount($space, $discount);

        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        Sanctum::actingAs($member, ['member-app']);

        $start = Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus');
        $end = $start->copy()->addMinutes($minutes);
        $body = array_filter([
            'space_id' => $space->id,
            'start_at' => $start->toIso8601String(),
            'end_at' => $end->toIso8601String(),
            'coupon_code' => $code,
        ]);

        // What the app shows on the confirm screen.
        $preview = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/preview', $body)->assertOk();
        $preview->assertJsonPath('data.is_available', true);
        $shown = $preview->json('data.breakdown.total.points');
        $this->assertSame($points, $shown, 'The preview prices the booking.');

        // Confirmed by card.
        $response = $this->withHeader('lang', 'en')
            ->postJson('/api/v1/member/bookings', $body + ['payment_method' => 'paymera'])
            ->assertCreated();

        $booking = Booking::sole();
        $payment = Payment::sole();
        $charge = $points * self::SYP_PER_POINT;

        // The time booked is the time asked for.
        $this->assertTrue($booking->start_at->equalTo($start));
        $this->assertTrue($booking->end_at->equalTo($end));
        $this->assertSame($minutes, (int) $booking->start_at->diffInMinutes($booking->end_at));
        $this->assertSame((int) ceil($rate * $minutes / 60), $booking->list_price_points);

        // The same points everywhere.
        $this->assertSame(BookingStatus::AwaitingPayment, $booking->status);
        $this->assertSame($shown, $booking->price_points);
        $this->assertSame($shown, $payment->amount_points);
        $this->assertSame($shown, $payment->points_to_credit);

        // And the same money: stored, answered and sent to Paymera.
        $this->assertSame('SYP', $payment->currency);
        $this->assertSame($charge, $payment->charged_amount);
        $response->assertJsonPath('payment.charged_amount', $charge)
            ->assertJsonPath('payment.amount_points', $shown)
            ->assertJsonPath('redirect_url', 'https://egate-t.paymera.test/start/pay-1/en');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'create-payment')
            && $request['amount'] === $charge);
    }

    private function discount(Space $space, ?string $discount): ?string
    {
        if ($discount === null) {
            return null;
        }

        if (str_starts_with($discount, 'offer')) {
            // 60 points an hour instead of the space's 100.
            $this->offerOnSpace($space, 60);
        }

        return match (true) {
            str_ends_with($discount, 'coupon:20%') => $this->coupon(Coupon::factory()->percentage(20)),
            str_ends_with($discount, 'coupon:30pts') => $this->coupon(Coupon::factory()->amountOff(30)),
            str_ends_with($discount, 'coupon:10%') => $this->coupon(Coupon::factory()->percentage(10), ['combinable_with_offers' => true]),
            str_ends_with($discount, 'coupon:20%-alone') => $this->coupon(Coupon::factory()->percentage(20), ['combinable_with_offers' => false]),
            default => null,
        };
    }

    /** @param  array<string, mixed>  $attributes */
    private function coupon(CouponFactory $factory, array $attributes = []): string
    {
        return $factory->create(['code' => 'CODE'] + $attributes)->code;
    }
}
