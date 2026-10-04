<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingBatchCreationService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Pricing\Enums\CouponRejection;
use App\Domain\Pricing\Exceptions\CouponRejectedException;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponLedger;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A coupon on several dates booked at once: one use per date, as far as
 * its limits allow — the dates past the limit are booked at the price
 * without it (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md, D1).
 */
class BookingBatchCouponTest extends TestCase
{
    use RefreshDatabase;

    private Space $room;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        $this->room = Space::factory()->room()->create(['hourly_rate_points' => 100, 'slot_granularity_minutes' => 60]);
        foreach ([DayOfWeek::Monday, DayOfWeek::Tuesday, DayOfWeek::Wednesday] as $day) {
            BusinessHour::factory()->create([
                'branch_id' => $this->room->building->branch_id,
                'day_of_week' => $day,
                'open_time' => '08:00',
                'close_time' => '20:00',
            ]);
        }

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
        app(WalletService::class)->creditGeneral($wallet, 1000, WalletTransactionSource::TopUp);
    }

    /** @return list<array{space_id: int, start_at: string, end_at: string}> */
    private function threeDays(?Space $space = null): array
    {
        return array_map(fn (string $day) => [
            'space_id' => ($space ?? $this->room)->id,
            'start_at' => "2026-08-{$day}T10:00:00+03:00",
            'end_at' => "2026-08-{$day}T11:00:00+03:00",
        ], ['17', '18', '19']);
    }

    /** @return list<Booking> */
    private function book(string $code, ?array $windows = null): array
    {
        return app(BookingBatchCreationService::class)->createMany($windows ?? $this->threeDays(), $this->member, paymentMethod: 'wallet', couponCode: $code);
    }

    public function test_the_coupon_covers_as_many_dates_as_its_limit_allows(): void
    {
        $coupon = Coupon::factory()->percentage(50)->create(['code' => 'TWICE', 'usage_limit' => 2]);

        $bookings = $this->book('TWICE');

        $this->assertSame([50, 50, 100], array_map(fn (Booking $b) => $b->price_points, $bookings));
        $this->assertSame(2, app(CouponLedger::class)->liveUsage($coupon));
    }

    public function test_a_coupon_no_date_can_use_is_refused_with_its_reason(): void
    {
        $hall = Space::factory()->room()->create(['hourly_rate_points' => 100, 'slot_granularity_minutes' => 60]);
        BusinessHour::factory()->create([
            'branch_id' => $hall->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);
        $coupon = Coupon::factory()->create(['code' => 'ROOMONLY']);
        $coupon->targets()->create(['purpose' => 'booking', 'space_id' => $this->room->id]);

        try {
            $this->book('ROOMONLY', [['space_id' => $hall->id, 'start_at' => '2026-08-17T10:00:00+03:00', 'end_at' => '2026-08-17T11:00:00+03:00']]);
            $this->fail('A coupon no date can use should be refused.');
        } catch (CouponRejectedException $e) {
            $this->assertSame(CouponRejection::NotApplicable, $e->rejection);
        }

        $this->assertSame(0, Booking::count());
    }

    public function test_the_batch_endpoint_takes_the_code(): void
    {
        Coupon::factory()->amountOff(10)->create(['code' => 'TEN']);
        Sanctum::actingAs($this->member, ['*']);

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'windows' => $this->threeDays(),
            'payment_method' => 'wallet',
            'coupon_code' => 'ten',
        ])->assertCreated();

        $this->assertSame([90, 90, 90], Booking::orderBy('start_at')->pluck('price_points')->all());
    }
}
