<?php

namespace Tests\Feature\Pricing;

use App\Domain\Experience\Models\Event;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
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
 * The best automatic offer live now for THIS member shows on the member
 * surface as the `sale` block: the original price struck through, the
 * offer price, and when it ends — and not at all when there is none
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
 */
class SaleLabelsTest extends TestCase
{
    use MakesOffers, RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'Asia/Damascus')->utc());

        $this->member = $this->actingAsMember();
    }

    private function actingAsMember(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    private function fetch(string $uri): TestResponse
    {
        return $this->withHeader('lang', 'en')->getJson($uri)->assertOk();
    }

    private function package(int $price): Plan
    {
        return Plan::factory()->create(['price_points' => $price, 'is_active' => true, 'is_public' => true, 'is_subscription' => true]);
    }

    /** @param  array<string, mixed>  $attributes */
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

    public function test_a_space_with_an_offer_shows_it(): void
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 100]);
        $this->offerOnSpace($space, 80, now()->subDay(), now()->addDays(6));

        $sale = $this->fetch("/api/v1/member/spaces/{$space->id}")->json('data.details.sale');

        $this->assertNotNull($sale);
        $this->assertSame(80, $sale['points']);
        $this->assertSame(100, $sale['original_points']);
        // How much the offer takes off, per hour like the rate it discounts.
        $this->assertSame(20, $sale['discount_points']);
        $this->assertSame(20, $sale['discount_percent']);
        $this->assertStringContainsString('/', $sale['discount_amount_label']);
        $this->assertSame(__('api.space.sale_discount_percent', ['percent' => 20], 'en'), $sale['discount_label']);
        $this->assertStringContainsString('/', $sale['original_label']);
        $this->assertStringContainsString('/', $sale['sale_label']);
        $this->assertNotSame($sale['original_label'], $sale['sale_label']);
        $this->assertSame(__('api.space.sale_ends', ['date' => '2026-10-11 10:00'], 'en'), $sale['ends_label']);

        $card = collect($this->fetch('/api/v1/member/spaces')->json('data.spaces'))->firstWhere('id', $space->id);
        $this->assertSame(80, $card['sale']['points']);
    }

    /** E8: a percentage leaving a fraction of a point per hour shows the rate rounded up. */
    public function test_a_fractional_offer_rate_is_shown_rounded_up(): void
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 15]);
        Coupon::factory()->automatic()->percentage(10)->create(['ends_at' => now()->addDays(6)]);

        $sale = $this->fetch("/api/v1/member/spaces/{$space->id}")->json('data.details.sale');

        $this->assertSame(14, $sale['points']);
        $this->assertSame(1, $sale['discount_points']);
        $this->assertSame(6, $sale['discount_percent']);
    }

    /** Upcoming offers are not announced (out of scope). */
    public function test_an_offer_not_yet_started_shows_none(): void
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 100]);
        $this->offerOnSpace($space, 80, now()->addDay(), now()->addDays(6));

        $this->assertNull($this->fetch("/api/v1/member/spaces/{$space->id}")->json('data.details.sale'));
    }

    /** Review Focus 1: a members-only offer shows only to its members. */
    public function test_a_members_only_offer_shows_only_to_its_members(): void
    {
        $plan = $this->package(120);
        $offer = $this->offerOnPlan($plan, 100, now()->subHour(), now()->addDays(2));
        $offer->users()->attach($this->member);

        $this->assertSame(100, $this->fetch("/api/v1/member/packages/{$plan->id}")->json('data.sale.points'));

        $this->actingAsMember();
        $this->assertNull($this->fetch("/api/v1/member/packages/{$plan->id}")->json('data.sale'));
        $this->assertNull(collect($this->fetch('/api/v1/member/packages')->json('data'))->firstWhere('id', $plan->id)['sale']);
    }

    /** Review Focus 2: an offer with no end says nothing about ending. */
    public function test_an_open_ended_offer_has_no_end_label(): void
    {
        $plan = $this->package(120);
        $this->offerOnPlan($plan, 100);

        $sale = $this->fetch("/api/v1/member/packages/{$plan->id}")->json('data.sale');

        $this->assertSame(100, $sale['points']);
        $this->assertNull($sale['ends_at']);
        $this->assertNull($sale['ends_label']);
    }

    /** A discount under one percent is announced by its amount, never as "0%". */
    public function test_a_tiny_discount_is_announced_by_its_amount(): void
    {
        $plan = $this->package(1000);
        $this->offerOnPlan($plan, 995, now()->subHour(), now()->addDays(2));

        $sale = $this->fetch("/api/v1/member/packages/{$plan->id}")->json('data.sale');

        $this->assertSame(5, $sale['discount_points']);
        $this->assertSame(0, $sale['discount_percent']);
        $this->assertSame(__('api.package.sale_discount_amount', ['amount' => $sale['discount_amount_label']], 'en'), $sale['discount_label']);
    }

    public function test_a_package_with_an_offer_shows_it(): void
    {
        $plan = $this->package(120);
        $this->offerOnPlan($plan, 100, now()->subHour(), now()->addDays(2));

        $sale = $this->fetch("/api/v1/member/packages/{$plan->id}")->json('data.sale');

        $this->assertNotNull($sale);
        $this->assertSame(100, $sale['points']);
        $this->assertSame(120, $sale['original_points']);
        $this->assertSame(20, $sale['discount_points']);
        // 16.67% is announced as 16%: a discount is never overstated.
        $this->assertSame(16, $sale['discount_percent']);
        $this->assertSame(__('api.package.sale_discount_percent', ['percent' => 16], 'en'), $sale['discount_label']);
        $this->assertSame(__('api.package.sale_ends', ['date' => '2026-10-07 10:00'], 'en'), $sale['ends_label']);
    }

    public function test_an_event_with_an_offer_shows_it(): void
    {
        $event = $this->event();
        $this->offerOnEvent($event, 75, ['ends_at' => now()->addDays(2)]);

        $sale = $this->fetch("/api/v1/member/events/{$event->id}")->json('data.sale');

        $this->assertNotNull($sale);
        $this->assertSame(75, $sale['points']);
        $this->assertSame(100, $sale['original_points']);
        $this->assertSame(25, $sale['discount_points']);
        $this->assertSame(25, $sale['discount_percent']);
        $this->assertSame(__('api.event.sale_discount_percent', ['percent' => 25], 'en'), $sale['discount_label']);
        $this->assertSame(__('api.event.sale_ends', ['date' => '2026-10-07 10:00'], 'en'), $sale['ends_label']);

        $card = collect($this->fetch('/api/v1/member/events')->json('data'))->firstWhere('id', $event->id);
        $this->assertSame(75, $card['sale']['points']);
    }

    /**
     * An offer on everything stops short of events; one targeted at events
     * reaches every paid one — a free event has nothing to take off.
     */
    public function test_only_an_offer_targeted_at_events_shows_on_them(): void
    {
        $paid = $this->event();
        $free = $this->event(['is_free' => true, 'price_points' => 0]);
        Coupon::factory()->automatic()->percentage(10)->create();

        $this->assertNull($this->fetch("/api/v1/member/events/{$paid->id}")->json('data.sale'));

        Coupon::factory()->automatic()->percentage(20)->forEvents()->create();

        $this->assertSame(80, $this->fetch("/api/v1/member/events/{$paid->id}")->json('data.sale.points'));
        // Null, never missing: the response keeps one shape with or without an offer.
        $this->assertArrayHasKey('sale', $this->fetch("/api/v1/member/events/{$free->id}")->json('data'));
        $this->assertNull($this->fetch("/api/v1/member/events/{$free->id}")->json('data.sale'));
    }

    /** Once registered, the member's price is locked: no offer is announced on it. */
    public function test_a_registered_member_sees_no_offer_on_the_event(): void
    {
        $event = $this->event();
        $this->offerOnEvent($event, 75);
        $event->attendees()->create(['user_id' => $this->member->id, 'name' => 'Member', 'email' => $this->member->email, 'status' => 'confirmed', 'price_points' => 75]);

        $this->assertNull($this->fetch("/api/v1/member/events/{$event->id}")->json('data.sale'));
    }
}
