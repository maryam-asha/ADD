<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponLedger;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Pricing\Concerns\MakesOffers;
use Tests\TestCase;

/**
 * A session priced at check-out — a walk-in, or a booking left unpaid — is
 * priced by PriceQuoteService too: the offer it locked, at its copied
 * terms, else (a walk-in, or a booking priced without one) the best offer
 * picked now over the time used and locked then; the booking's plan
 * discount; its coupon at its copied terms
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md, "Locked terms").
 * A room at 100 points an hour; 2026-08-17 is a Monday.
 */
class SessionClosurePricingTest extends TestCase
{
    use MakesOffers, RefreshDatabase;

    private Space $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        $this->room = Space::factory()->room()->create(['hourly_rate_points' => 100]);
        BusinessHour::factory()->create([
            'branch_id' => $this->room->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse("2026-08-17 {$time}", 'Asia/Damascus');
    }

    public function test_a_walk_in_picks_its_offer_at_closure_and_locks_it(): void
    {
        $offer = $this->offerOnSpace($this->room, 60, $this->at('00:00')->utc(), $this->at('10:30')->utc());
        $walkin = WalkinSession::factory()->create(['space_id' => $this->room->id, 'checked_in_at' => $this->at('10:00')->utc()]);

        app(SessionClosureService::class)->closeOut($walkin, $this->at('11:00'));
        $walkin->refresh();

        // 30 min at 60/h + 30 min at 100/h.
        $this->assertSame(80, $walkin->price_points);
        $this->assertSame(100, $walkin->list_price_points);
        $this->assertSame('8', (string) $walkin->amount_owed);

        $row = app(CouponLedger::class)->offerFor($walkin);
        $this->assertSame($offer->id, $row->coupon_id);
        $this->assertSame(100, $row->base_points);
        $this->assertSame(20, $row->points);
    }

    /** Review Focus 5: an unpaid booking closed early keeps its offer's copied terms. */
    public function test_an_unpaid_booking_closed_early_keeps_its_locked_offer_terms(): void
    {
        $offer = Coupon::factory()->automatic()->percentage(50)->create();
        $member = User::factory()->create();
        $member->assignRole('member');
        $booking = app(BookingCreationService::class)->create($this->room, $member, $this->at('10:00'), $this->at('12:00'), paymentMethod: 'cash');
        $this->assertSame(100, $booking->price_points);

        $offer->update(['discount_value' => 10]);
        $booking->forceFill(['checked_in_at' => $this->at('10:00')->utc()])->save();
        Carbon::setTestNow($this->at('11:00'));

        app(SessionClosureService::class)->closeOut($booking->refresh(), $this->at('11:00'));
        $booking->refresh();

        $this->assertSame(50, $booking->price_points);
        $row = app(CouponLedger::class)->offerFor($booking);
        $this->assertSame(100, $row->base_points);
        $this->assertSame(50, $row->points);
    }

    /** An unpaid booking repriced at check-out keeps its coupon. */
    public function test_an_unpaid_booking_closed_early_keeps_its_coupon(): void
    {
        Coupon::factory()->amountOff(30)->create(['code' => 'THIRTY']);
        $member = User::factory()->create();
        $member->assignRole('member');
        $booking = app(BookingCreationService::class)->create($this->room, $member, $this->at('10:00'), $this->at('12:00'), paymentMethod: 'cash', couponCode: 'THIRTY');
        $this->assertSame(170, $booking->price_points);

        $booking->forceFill(['checked_in_at' => $this->at('10:00')->utc()])->save();
        Carbon::setTestNow($this->at('11:00'));

        $quote = app(SessionClosureService::class)->quote($booking->refresh(), $this->at('11:00'));
        app(SessionClosureService::class)->closeOut($booking, $this->at('11:00'));
        $booking->refresh();

        $this->assertSame(70, $quote['points']);
        $this->assertSame(70, $booking->price_points);
        $this->assertSame(100, $booking->list_price_points);
        $redemption = app(CouponLedger::class)->couponFor($booking);
        $this->assertSame(30, $redemption->points);
        $this->assertSame(100, $redemption->base_points);
    }

    /** A booking already paid keeps the price it was paid at. */
    public function test_a_paid_booking_is_not_repriced(): void
    {
        $booking = Booking::factory()->create([
            'space_id' => $this->room->id,
            'start_at' => $this->at('10:00')->utc(),
            'end_at' => $this->at('12:00')->utc(),
            'checked_in_at' => $this->at('10:00')->utc(),
            'payment_state' => 'paid',
            'price_points' => 150,
            'list_price_points' => 200,
        ]);

        app(SessionClosureService::class)->closeOut($booking, $this->at('11:00'));

        $this->assertSame(150, $booking->refresh()->price_points);
        $this->assertSame(200, $booking->list_price_points);
    }
}
