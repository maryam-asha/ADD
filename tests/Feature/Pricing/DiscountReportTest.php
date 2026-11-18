<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Enums\DiscountKind;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\AppliedDiscount;
use App\Domain\Pricing\Models\Coupon;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET admin/reports/discounts: every operation with a discount or saving
 * in the period, with its status; cancelled, rejected, failed and reversed
 * ones are listed but left out of the totals, and savings are totalled
 * apart (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
 */
class DiscountReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'Asia/Damascus')->utc());
        $this->signInAs('operations');
    }

    private function signInAs(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /** @param  array<string, mixed>  $terms */
    private function row(Model $record, DiscountKind $kind, int $base, int $points, string $at = '2026-10-06 10:00:00', array $terms = []): AppliedDiscount
    {
        $column = match (true) {
            $record instanceof Booking => 'booking_id',
            $record instanceof Payment => 'payment_id',
        };
        $coupon = in_array($kind, [DiscountKind::Offer, DiscountKind::Coupon], true) ? Coupon::factory()->automatic()->create() : null;

        $row = new AppliedDiscount([
            $column => $record->getKey(),
            'kind' => $kind,
            'user_id' => $record->user_id,
            'purpose' => $record instanceof Booking ? PricingPurpose::Booking : PricingPurpose::PlanPurchase,
            'coupon_id' => $coupon?->id,
            'plan_discount_id' => null,
            'discount_type' => $coupon !== null ? 'percentage' : null,
            'discount_value' => $coupon !== null ? 10 : null,
            'base_points' => $base,
            'points' => $points,
            'percent' => AppliedDiscount::percentOf($points, $base),
            'terms' => $terms,
        ]);
        $row->created_at = Carbon::parse($at, 'Asia/Damascus')->utc();
        $row->save();

        return $row;
    }

    private function booking(int $list, int $final, BookingStatus $status = BookingStatus::Confirmed): Booking
    {
        return Booking::factory()->create(['list_price_points' => $list, 'price_points' => $final, 'status' => $status]);
    }

    /** The spec's example: list 200, an offer of 40 and a coupon of 24 → 136. */
    private function exampleBooking(string $at = '2026-10-06 10:00:00'): Booking
    {
        $booking = $this->booking(200, 136);
        $this->row($booking, DiscountKind::Offer, 200, 40, $at, ['name' => ['en' => 'Meeting rooms week', 'ar' => 'أسبوع']]);
        $this->row($booking, DiscountKind::Coupon, 160, 24, $at, ['code' => 'WELCOME20']);

        return $booking;
    }

    private function report(string $query = '')
    {
        return $this->withHeader('lang', 'en')->getJson('/api/v1/admin/reports/discounts'.($query !== '' ? '?'.$query : ''))->assertOk();
    }

    public function test_operations_are_listed_with_every_discount_and_their_status(): void
    {
        $booking = $this->exampleBooking();

        $row = $this->report('from=2026-10-06&to=2026-10-06')->assertJsonCount(1, 'data')->json('data.0');

        $this->assertSame(['type' => 'booking', 'id' => $booking->id, 'reference' => $booking->reference], $row['record']);
        $this->assertSame($booking->user_id, $row['member']['id']);
        $this->assertSame('booking', $row['purpose']);
        $this->assertSame('confirmed', $row['status']);
        $this->assertTrue($row['counted']);
        $this->assertSame(200, $row['list_points']);
        $this->assertSame(136, $row['final_points']);
        $this->assertSame([
            ['kind' => 'offer', 'name' => 'Meeting rooms week', 'code' => null, 'points' => 40, 'percent' => 20],
            ['kind' => 'coupon', 'name' => null, 'code' => 'WELCOME20', 'points' => 24, 'percent' => 15],
        ], $row['discounts']);
        $this->assertNull($row['saving']);
    }

    public function test_cancelled_operations_are_listed_but_not_totalled(): void
    {
        $this->exampleBooking();
        $cancelled = $this->exampleBooking();
        $cancelled->forceFill(['status' => BookingStatus::Cancelled])->save();

        $response = $this->report();

        $response->assertJsonCount(2, 'data');
        $this->assertSame([true, false], collect($response->json('data'))->sortBy('record.id')->pluck('counted')->values()->all());
        $response->assertJsonPath('meta.totals.operations', 1)
            ->assertJsonPath('meta.totals.list_points', 200)
            ->assertJsonPath('meta.totals.final_points', 136)
            ->assertJsonPath('meta.totals.points_by_kind.offer', 40)
            ->assertJsonPath('meta.totals.points_by_kind.coupon', 24)
            // floor(64 × 100 / 200)
            ->assertJsonPath('meta.totals.effective_percent', 32);
    }

    public function test_savings_are_totalled_apart_and_never_subtracted_from_list(): void
    {
        $payment = Payment::factory()->create(['purpose' => PaymentPurpose::PlanPurchase, 'status' => PaymentStatus::Accepted, 'list_price_points' => 900, 'amount_points' => 810, 'points_to_credit' => 0]);
        $this->row($payment, DiscountKind::Offer, 900, 90);
        $this->row($payment, DiscountKind::DurationSaving, 1500, 600, terms: ['reference_name' => 'Monthly', 'units' => 12]);

        $response = $this->report();

        $row = $response->json('data.0');
        $this->assertSame('payment', $row['record']['type']);
        $this->assertSame(['points' => 600, 'percent' => 40, 'reference_name' => 'Monthly', 'units' => 12], $row['saving']);
        $this->assertCount(1, $row['discounts']);
        $response->assertJsonPath('meta.totals.saving_points', 600)
            ->assertJsonPath('meta.totals.points_by_kind.offer', 90)
            ->assertJsonPath('meta.totals.list_points', 900)
            ->assertJsonPath('meta.totals.final_points', 810);
    }

    public function test_filters_kind_purpose_status_and_dates(): void
    {
        $couponed = $this->booking(100, 90);
        $this->row($couponed, DiscountKind::Coupon, 100, 10, '2026-10-01 10:00:00', ['code' => 'TEN']);
        $this->row($couponed, DiscountKind::Offer, 100, 0, '2026-10-01 10:00:00');
        $payment = Payment::factory()->create(['purpose' => PaymentPurpose::PlanPurchase, 'status' => PaymentStatus::Accepted, 'list_price_points' => 120, 'amount_points' => 100, 'points_to_credit' => 0]);
        $this->row($payment, DiscountKind::Offer, 120, 20, '2026-10-05 10:00:00');
        $cancelled = $this->booking(100, 80, BookingStatus::Cancelled);
        $this->row($cancelled, DiscountKind::Offer, 100, 20, '2026-10-03 10:00:00');

        $ids = fn (string $query) => collect($this->report($query)->json('data'))->map(fn ($r) => $r['record']['type'].':'.$r['record']['id'])->sort()->values()->all();

        // A record with a coupon is listed with all of its rows.
        $this->assertSame(["booking:{$couponed->id}"], $ids('kind=coupon'));
        $this->assertCount(2, $this->report('kind=coupon')->json('data.0.discounts'));
        $this->assertSame(["payment:{$payment->id}"], $ids('purpose=plan_purchase'));
        $this->assertSame(["booking:{$cancelled->id}"], $ids('status=void'));
        $this->assertSame(["booking:{$cancelled->id}", "payment:{$payment->id}"], $ids('from=2026-10-03'));
        // Local dates: 2026-10-01 10:00 Damascus is inside 2026-10-01.
        $this->assertSame(["booking:{$couponed->id}"], $ids('from=2026-10-01&to=2026-10-01'));
    }

    public function test_the_effective_percent_rounds_down(): void
    {
        $booking = $this->booking(300, 200);
        $this->row($booking, DiscountKind::Offer, 300, 100);

        $this->report()->assertJsonPath('meta.totals.effective_percent', 33);
    }

    public function test_bad_filters_are_refused(): void
    {
        $this->withHeader('lang', 'en')->getJson('/api/v1/admin/reports/discounts?kind=gift&status=maybe&from=06-10-2026')
            ->assertJsonValidationErrors(['kind', 'status', 'from']);
    }

    public function test_admins_can_read_it_and_members_cannot(): void
    {
        $this->signInAs('admin');
        $this->report();

        $this->signInAs('member');
        $this->getJson('/api/v1/admin/reports/discounts')->assertForbidden();
    }
}
