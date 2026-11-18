<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Services\BookingCreationService;
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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Pricing\Concerns\MakesOffers;
use Tests\TestCase;

/**
 * The price the member sees before booking is the one PriceQuoteService
 * composes, and a booking's breakdown afterwards is read from what it was
 * actually charged — never re-priced at today's rates.
 */
class BookingPreviewCouponTest extends TestCase
{
    use MakesOffers, RefreshDatabase;

    private Space $room;

    private User $member;

    private Coupon $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        $this->room = Space::factory()->room()->create(['hourly_rate_points' => 100]);
        $this->offer = $this->offerOnSpace($this->room, 80, Carbon::parse('2026-08-17 00:00:00', 'Asia/Damascus')->utc(), Carbon::parse('2026-08-18 00:00:00', 'Asia/Damascus')->utc(), ['name' => ['en' => 'Meeting rooms week', 'ar' => 'أسبوع غرف الاجتماعات']]);
        BusinessHour::factory()->create([
            'branch_id' => $this->room->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
        app(WalletService::class)->creditGeneral($wallet, 1000, WalletTransactionSource::TopUp);
        Sanctum::actingAs($this->member, ['*']);
    }

    private function preview(?string $code = null): TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/preview', array_filter([
            'space_id' => $this->room->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'coupon_code' => $code,
        ]));
    }

    /** @return array<string, int> */
    private function lines(TestResponse $response): array
    {
        return collect($response->json('data.breakdown.lines'))->pluck('points', 'key')->all();
    }

    public function test_the_preview_shows_the_sale(): void
    {
        $response = $this->preview()->assertOk();

        $this->assertSame(['base' => 100, 'sale' => -20], $this->lines($response));
        $response->assertJsonPath('data.breakdown.total.points', 80);
    }

    /** The offer's line is labelled with its name, or "Offer" when it has none. */
    public function test_the_offer_line_carries_the_offer_name(): void
    {
        $label = fn () => collect($this->preview()->assertOk()->json('data.breakdown.lines'))->firstWhere('key', 'sale')['label'];

        $this->assertSame('Meeting rooms week', $label());

        $this->offer->update(['name' => null]);
        $this->assertSame('Offer', $label());
    }

    public function test_the_preview_takes_a_coupon_and_says_what_it_did(): void
    {
        Coupon::factory()->percentage(25)->create(['code' => 'QUARTER']);

        $response = $this->preview('quarter')->assertOk();

        $this->assertSame(['base' => 100, 'sale' => -20, 'coupon' => -20], $this->lines($response));
        $response
            ->assertJsonPath('data.breakdown.total.points', 60)
            ->assertJsonPath('data.coupon.code', 'QUARTER')
            ->assertJsonPath('data.coupon.applied', true)
            ->assertJsonPath('data.coupon.points_discounted', 20);
    }

    public function test_a_coupon_that_cannot_be_used_is_refused_with_its_reason(): void
    {
        Coupon::factory()->create(['code' => 'OLD', 'ends_at' => now()->subDay()]);

        $this->preview('OLD')
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.coupon.expired', [], 'en'));
    }

    /** What it was charged, even after the sale ends and the rate moves. */
    public function test_a_bookings_breakdown_is_what_it_was_charged(): void
    {
        Coupon::factory()->amountOff(10)->create(['code' => 'TEN']);
        $start = Carbon::parse('2026-08-17 10:00', 'Asia/Damascus');
        $booking = app(BookingCreationService::class)->create($this->room, $this->member, $start, $start->copy()->addHour(), paymentMethod: 'wallet', couponCode: 'TEN');

        $this->room->update(['hourly_rate_points' => 500]);
        $this->offer->update(['is_active' => false]);

        $response = $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$booking->id}")->assertOk();

        $response->assertJsonPath('data.total.points', 70);
    }
}
