<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Enums\DiscountKind;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponLedger;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every booking, registration and payment says what was taken off it — the
 * offer, the coupon, the plan discount — as it was when the price was
 * locked: renaming or changing the offer later does not rewrite it.
 */
class DiscountsOnRecordsTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Coupon $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        $this->offer = Coupon::factory()->automatic()->percentage(20)->create(['name' => ['en' => 'Rooms week', 'ar' => 'أسبوع الغرف']]);
    }

    private function applyOffer(Model $record, PricingPurpose $purpose): void
    {
        app(CouponLedger::class)->write($record, $this->member, $purpose, DiscountKind::Offer, CouponLedger::couponAttributes($this->offer, 100, 20));
    }

    private function asAdmin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
    }

    public function test_a_members_booking_names_the_offer_as_it_was(): void
    {
        $booking = Booking::factory()->create(['user_id' => $this->member->id, 'status' => BookingStatus::Confirmed]);
        $this->applyOffer($booking, PricingPurpose::Booking);
        $this->offer->update(['name' => ['en' => 'Renamed', 'ar' => 'تغيّر'], 'discount_value' => 50]);
        Sanctum::actingAs($this->member, ['*']);

        $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.discounts.0.kind', 'offer')
            ->assertJsonPath('data.discounts.0.offer_id', $this->offer->id)
            ->assertJsonPath('data.discounts.0.name', 'Rooms week')
            ->assertJsonPath('data.discounts.0.discount_type', 'percentage')
            ->assertJsonPath('data.discounts.0.discount_value', 20)
            ->assertJsonPath('data.discounts.0.base_points', 100)
            ->assertJsonPath('data.discounts.0.points', 20)
            ->assertJsonPath('data.discounts.0.percent', 20);
    }

    public function test_a_booking_without_a_discount_has_none(): void
    {
        $booking = Booking::factory()->create(['user_id' => $this->member->id]);
        Sanctum::actingAs($this->member, ['*']);

        $this->getJson("/api/v1/member/bookings/{$booking->id}")->assertOk()->assertJsonPath('data.discounts', []);
    }

    public function test_the_dashboard_booking_list_and_detail(): void
    {
        $booking = Booking::factory()->create(['user_id' => $this->member->id, 'status' => BookingStatus::Confirmed]);
        $this->applyOffer($booking, PricingPurpose::Booking);
        $this->asAdmin();

        $this->getJson("/api/v1/admin/bookings/{$booking->id}")->assertOk()->assertJsonPath('data.discounts.0.offer_id', $this->offer->id);
        $this->getJson('/api/v1/admin/bookings')->assertOk()->assertJsonPath('data.0.discounts.0.offer_id', $this->offer->id);
    }

    public function test_a_coupon_row_names_its_code(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'WELCOME']);
        $booking = Booking::factory()->create(['user_id' => $this->member->id, 'status' => BookingStatus::Confirmed]);
        app(CouponLedger::class)->record($coupon, $this->member, $booking, PricingPurpose::Booking, 100, 10);
        $this->asAdmin();

        $this->getJson("/api/v1/admin/bookings/{$booking->id}")
            ->assertJsonPath('data.discounts.0.kind', 'coupon')
            ->assertJsonPath('data.discounts.0.coupon_id', $coupon->id)
            ->assertJsonPath('data.discounts.0.offer_id', null)
            ->assertJsonPath('data.discounts.0.code', 'WELCOME');
    }

    public function test_an_event_registration(): void
    {
        $attendee = EventAttendee::factory()->create(['user_id' => $this->member->id]);
        $this->applyOffer($attendee, PricingPurpose::EventRegistration);
        $this->asAdmin();

        $this->getJson("/api/v1/admin/events/{$attendee->event_id}/attendees")
            ->assertOk()
            ->assertJsonPath('data.0.discounts.0.offer_id', $this->offer->id);
    }

    public function test_a_payment(): void
    {
        $payment = Payment::factory()->create(['user_id' => $this->member->id]);
        $this->applyOffer($payment, PricingPurpose::PlanPurchase);

        Sanctum::actingAs($this->member, ['*']);
        $this->getJson("/api/v1/member/payments/{$payment->id}")->assertOk()->assertJsonPath('data.discounts.0.offer_id', $this->offer->id);

        $this->asAdmin();
        $this->getJson("/api/v1/admin/payments/{$payment->id}")->assertOk()->assertJsonPath('data.discounts.0.offer_id', $this->offer->id);
        $this->getJson('/api/v1/admin/payments')->assertOk()->assertJsonPath('data.0.discounts.0.offer_id', $this->offer->id);
    }

    /** A page of records costs one ledger query, not one per row. */
    public function test_lists_read_the_ledger_once_per_page(): void
    {
        $this->asAdmin();
        $event = EventAttendee::factory()->create(['user_id' => $this->member->id])->event;
        foreach (range(1, 3) as $n) {
            $this->applyOffer(Booking::factory()->create(['user_id' => $this->member->id]), PricingPurpose::Booking);
            $this->applyOffer(Payment::factory()->create(['user_id' => $this->member->id]), PricingPurpose::PlanPurchase);
            $this->applyOffer(EventAttendee::factory()->create(['event_id' => $event->id, 'user_id' => $this->member->id]), PricingPurpose::EventRegistration);
        }

        foreach (['/api/v1/admin/bookings', '/api/v1/admin/payments', "/api/v1/admin/events/{$event->id}/attendees", "/api/v1/admin/events/{$event->id}/attendees/pending"] as $url) {
            $reads = 0;
            DB::listen(function (QueryExecuted $query) use (&$reads) {
                $reads += str_contains($query->sql, 'applied_discounts') ? 1 : 0;
            });
            $this->getJson($url)->assertOk();
            $this->assertLessThanOrEqual(1, $reads, $url);
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }

        Sanctum::actingAs($this->member, ['*']);
        $reads = 0;
        DB::listen(function (QueryExecuted $query) use (&$reads) {
            $reads += str_contains($query->sql, 'applied_discounts') ? 1 : 0;
        });
        $this->getJson('/api/v1/member/payments')->assertOk();
        $this->assertLessThanOrEqual(1, $reads, 'member/payments');
    }
}
