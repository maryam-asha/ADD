<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Enums\DiscountKind;
use App\Domain\Pricing\Enums\PricingPurpose;
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
 * Which offers ran when: every change an admin makes to an offer or a
 * coupon is kept as a revision, so a period can be asked what was running
 * in it, on what terms, and what it gave — even after the offer was
 * changed, switched off or deleted.
 *
 * Dates are Damascus days; the clock is moved in UTC (Damascus is +3).
 */
class OfferHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->at('2026-10-01 09:00');

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
    }

    private function at(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    }

    /** @param  array<string, mixed>  $attributes */
    private function createOffer(array $attributes = []): int
    {
        return $this->postJson('/api/v1/admin/coupons', $attributes + [
            'is_automatic' => true,
            'name' => ['en' => 'Rooms week', 'ar' => 'أسبوع الغرف'],
            'discount_type' => 'percentage',
            'discount_value' => 20,
        ])->assertCreated()->json('data.id');
    }

    /** A change, sent as the dashboard form sends it: the whole discount with it. */
    private function change(int $id, array $changes): void
    {
        $this->patchJson("/api/v1/admin/coupons/{$id}", $changes + ['discount_type' => 'percentage', 'discount_value' => 20])->assertOk();
    }

    private function report(string $from, string $to, array $query = []): TestResponse
    {
        return $this->withHeader('lang', 'en')->getJson('/api/v1/admin/reports/offers?'.http_build_query(['from' => $from, 'to' => $to] + $query));
    }

    public function test_every_change_is_kept_as_a_revision(): void
    {
        $id = $this->createOffer();
        $this->at('2026-10-02 09:00');
        $this->change($id, ['discount_value' => 30]);
        $this->at('2026-10-03 09:00');
        $this->deleteJson("/api/v1/admin/coupons/{$id}")->assertNoContent();

        $this->getJson("/api/v1/admin/coupons/{$id}/revisions")->assertNotFound();
        $this->assertDatabaseCount('coupon_revisions', 3);
        $this->assertDatabaseHas('coupon_revisions', ['coupon_id' => $id, 'action' => 'updated', 'discount_value' => 30]);
        $this->assertDatabaseHas('coupon_revisions', ['coupon_id' => $id, 'action' => 'deleted']);
    }

    public function test_an_offers_revisions_newest_first(): void
    {
        $id = $this->createOffer();
        $this->at('2026-10-02 09:00');
        $this->change($id, ['discount_value' => 30, 'ends_at' => '2026-10-20T00:00:00+03:00']);

        $this->getJson("/api/v1/admin/coupons/{$id}/revisions")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.action', 'updated')
            ->assertJsonPath('data.0.discount_value', 30)
            ->assertJsonPath('data.0.ends_at', '2026-10-19T21:00:00+00:00')
            ->assertJsonPath('data.0.recorded_at', '2026-10-02T09:00:00+00:00')
            ->assertJsonPath('data.1.action', 'created')
            ->assertJsonPath('data.1.discount_value', 20);
    }

    public function test_a_period_lists_the_offers_that_ran_in_it_on_the_terms_they_had(): void
    {
        // Runs 3rd–10th (Damascus) at 20%; on the 5th it is raised to 30%.
        $id = $this->createOffer(['starts_at' => '2026-10-03T00:00:00+03:00', 'ends_at' => '2026-10-11T00:00:00+03:00']);
        $this->at('2026-10-04 21:00'); // the 5th, midnight in Damascus
        $this->change($id, ['discount_value' => 30]);
        $this->at('2026-10-20 09:00');

        $this->report('2026-10-01', '2026-10-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.name', 'Rooms week')
            ->assertJsonCount(2, 'data.0.periods')
            ->assertJsonPath('data.0.periods.0.from', '2026-10-02T21:00:00+00:00')
            ->assertJsonPath('data.0.periods.0.to', '2026-10-04T21:00:00+00:00')
            ->assertJsonPath('data.0.periods.0.discount_value', 20)
            ->assertJsonPath('data.0.periods.1.from', '2026-10-04T21:00:00+00:00')
            ->assertJsonPath('data.0.periods.1.to', '2026-10-10T21:00:00+00:00')
            ->assertJsonPath('data.0.periods.1.discount_value', 30);

        // Asked about the 1st–3rd only: the first days, clipped to the period.
        $this->report('2026-10-01', '2026-10-03')
            ->assertJsonCount(1, 'data.0.periods')
            ->assertJsonPath('data.0.periods.0.from', '2026-10-02T21:00:00+00:00')
            ->assertJsonPath('data.0.periods.0.to', '2026-10-03T21:00:00+00:00');

        // Before it started, nothing ran.
        $this->report('2026-09-01', '2026-10-02')->assertJsonCount(0, 'data');
    }

    public function test_a_switched_off_stretch_is_not_a_running_one(): void
    {
        $id = $this->createOffer();
        $this->at('2026-10-03 09:00');
        $this->change($id, ['is_active' => false]);
        $this->at('2026-10-05 09:00');
        $this->change($id, ['is_active' => true]);
        $this->at('2026-10-06 09:00');

        $this->report('2026-10-01', '2026-10-06')
            ->assertJsonCount(2, 'data.0.periods')
            ->assertJsonPath('data.0.periods.0.from', '2026-10-01T09:00:00+00:00')
            ->assertJsonPath('data.0.periods.0.to', '2026-10-03T09:00:00+00:00')
            ->assertJsonPath('data.0.periods.1.from', '2026-10-05T09:00:00+00:00')
            // Still running: up to the end of the period asked about.
            ->assertJsonPath('data.0.periods.1.to', '2026-10-06T21:00:00+00:00');
    }

    public function test_a_change_that_keeps_the_terms_does_not_split_a_period(): void
    {
        $id = $this->createOffer();
        $this->at('2026-10-02 09:00');
        $this->change($id, ['name' => ['en' => 'Renamed', 'ar' => 'تغيّر']]);

        $this->report('2026-10-01', '2026-10-02')
            ->assertJsonCount(1, 'data.0.periods')
            ->assertJsonPath('data.0.name', 'Renamed');
    }

    public function test_a_deleted_offer_still_shows_where_it_ran(): void
    {
        $id = $this->createOffer();
        $this->at('2026-10-02 09:00');
        $this->deleteJson("/api/v1/admin/coupons/{$id}")->assertNoContent();

        $this->report('2026-10-01', '2026-10-05')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.deleted', true)
            ->assertJsonPath('data.0.periods.0.to', '2026-10-02T09:00:00+00:00');
    }

    public function test_what_each_offer_gave_in_the_period(): void
    {
        $id = $this->createOffer();
        $member = User::factory()->create();
        $ledger = app(CouponLedger::class);
        $offer = Coupon::findOrFail($id);
        foreach ([BookingStatus::Confirmed, BookingStatus::Confirmed, BookingStatus::Cancelled] as $status) {
            $ledger->write(Booking::factory()->create(['user_id' => $member->id, 'status' => $status]), $member, PricingPurpose::Booking, DiscountKind::Offer, CouponLedger::couponAttributes($offer, 100, 20));
        }

        // A cancelled booking gave nothing.
        $this->report('2026-10-01', '2026-10-01')
            ->assertJsonPath('data.0.operations', 2)
            ->assertJsonPath('data.0.points', 40);
    }

    public function test_offers_by_default_and_coupons_on_request(): void
    {
        $this->createOffer();
        $this->postJson('/api/v1/admin/coupons', ['code' => 'WELCOME', 'name' => ['en' => 'Welcome', 'ar' => 'ترحيب'], 'discount_type' => 'percentage', 'discount_value' => 10])->assertCreated();

        $this->report('2026-10-01', '2026-10-01')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', null);
        $this->report('2026-10-01', '2026-10-01', ['type' => 'coupon'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'WELCOME');
    }

    public function test_a_period_is_required(): void
    {
        $this->getJson('/api/v1/admin/reports/offers')->assertJsonValidationErrors(['from', 'to']);
    }
}
