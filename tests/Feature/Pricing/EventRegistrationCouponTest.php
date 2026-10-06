<?php

namespace Tests\Feature\Pricing;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponLedger;
use App\Support\Enums\EventPaymentState;
use App\Support\Enums\EventRegistrationStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A coupon on a paid event's registration
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 * A registration a coupon makes free is paid — the door lets in only a
 * paid registration (D10).
 */
class EventRegistrationCouponTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
        app(WalletService::class)->creditGeneral($wallet, 500, WalletTransactionSource::TopUp);
        Sanctum::actingAs($this->member, ['*']);
    }

    private function event(array $attributes = []): Event
    {
        return Event::create(array_merge([
            'title' => ['en' => 'Masterclass', 'ar' => 'دورة'],
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addHours(2),
            'location' => 'Main Hall',
            'capacity' => 20,
            'is_free' => false,
            'price_points' => 100,
            'status' => 'published',
            'requires_approval' => false,
        ], $attributes));
    }

    private function register(Event $event, string $code, ?string $method = null)
    {
        return $this->withHeader('lang', 'en')->postJson("/api/v1/member/events/{$event->id}/register", array_filter([
            'coupon_code' => $code,
            'payment_method' => $method,
        ]));
    }

    private function balance(): int
    {
        return app(WalletService::class)->generalBalance(app(WalletService::class)->walletFor(OwnerType::User, $this->member->id));
    }

    public function test_a_coupon_lowers_what_the_registration_costs(): void
    {
        $coupon = Coupon::factory()->percentage(30)->create(['code' => 'EARLY']);

        $this->register($this->event(), 'early')->assertCreated();

        $attendee = EventAttendee::sole();
        $this->assertSame(70, $attendee->price_points);
        $this->assertSame(100, $attendee->list_price_points);
        $this->assertSame(EventPaymentState::Paid, $attendee->payment_state);
        $this->assertSame(430, $this->balance());
        $this->assertSame($coupon->id, app(CouponLedger::class)->couponFor($attendee)->coupon_id);
    }

    public function test_a_registration_made_free_is_paid(): void
    {
        Coupon::factory()->percentage(100)->create(['code' => 'GUEST']);

        $this->register($this->event(), 'GUEST', 'cash')->assertCreated();

        $attendee = EventAttendee::sole();
        $this->assertSame(0, $attendee->price_points);
        $this->assertSame(EventPaymentState::Paid, $attendee->payment_state);
        $this->assertSame(500, $this->balance());
    }

    /** A pending registration holds its use; rejecting it gives the use back. */
    public function test_a_rejected_registration_gives_the_use_back(): void
    {
        $coupon = Coupon::factory()->percentage(50)->create(['code' => 'HALF']);

        $this->register($this->event(['requires_approval' => true]), 'HALF')->assertCreated();

        $attendee = EventAttendee::sole();
        $this->assertSame(50, $attendee->price_points);
        $this->assertSame(1, app(CouponLedger::class)->liveUsage($coupon));

        $attendee->forceFill(['status' => EventRegistrationStatus::Rejected])->save();
        $this->assertSame(0, app(CouponLedger::class)->liveUsage($coupon));
    }

    private function approve(EventAttendee $attendee)
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $this->withHeader('lang', 'en')->postJson("/api/v1/admin/events/{$attendee->event_id}/attendees/{$attendee->id}/approve");
    }

    /** Approval charges what the registration costs with its coupon, not the event's price. */
    public function test_an_approved_registration_is_charged_its_coupon_price(): void
    {
        Coupon::factory()->percentage(50)->create(['code' => 'HALF']);
        $this->register($this->event(['requires_approval' => true]), 'HALF')->assertCreated();
        $this->assertSame(500, $this->balance());

        $this->approve(EventAttendee::sole())->assertOk();

        $attendee = EventAttendee::sole();
        $this->assertSame(EventRegistrationStatus::Approved, $attendee->status);
        $this->assertSame(EventPaymentState::Paid, $attendee->payment_state);
        $this->assertSame(450, $this->balance());
    }

    /** A registration a coupon made free has nothing to charge when it is approved. */
    public function test_a_registration_made_free_is_approved_without_a_charge(): void
    {
        Coupon::factory()->percentage(100)->create(['code' => 'GUEST']);
        $this->register($this->event(['requires_approval' => true]), 'GUEST')->assertCreated();

        $this->approve(EventAttendee::sole())->assertOk();

        $attendee = EventAttendee::sole();
        $this->assertSame(EventRegistrationStatus::Approved, $attendee->status);
        $this->assertSame(EventPaymentState::Paid, $attendee->payment_state);
        $this->assertSame(500, $this->balance());
    }

    public function test_a_coupon_on_a_free_event_is_refused(): void
    {
        Coupon::factory()->create(['code' => 'FREEBIE']);

        $this->register($this->event(['is_free' => true, 'price_points' => 0]), 'FREEBIE')
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.coupon.not_applicable', [], 'en'));

        $this->assertSame(0, EventAttendee::count());
    }
}
