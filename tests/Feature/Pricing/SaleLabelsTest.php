<?php

namespace Tests\Feature\Pricing;

use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A live sale shows on the member surface as labels: the original price
 * struck through, the sale price, and when it ends — and not at all when
 * there is none (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 */
class SaleLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'Asia/Damascus')->utc());

        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
    }

    private function fetch(string $uri): TestResponse
    {
        return $this->withHeader('lang', 'en')->getJson($uri)->assertOk();
    }

    public function test_a_space_on_sale_shows_its_sale(): void
    {
        $space = Space::factory()->room()->create([
            'hourly_rate_points' => 100,
            'sale_hourly_rate_points' => 80,
            'sale_starts_at' => now()->subDay(),
            'sale_ends_at' => now()->addDays(6),
        ]);

        $sale = $this->fetch("/api/v1/member/spaces/{$space->id}")->json('data.details.sale');

        $this->assertNotNull($sale);
        $this->assertSame(80, $sale['points']);
        $this->assertSame(100, $sale['original_points']);
        // How much the sale takes off, per hour like the rate it discounts.
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

    public function test_a_space_without_a_live_sale_shows_none(): void
    {
        $space = Space::factory()->room()->create([
            'hourly_rate_points' => 100,
            'sale_hourly_rate_points' => 80,
            'sale_starts_at' => now()->addDay(),
            'sale_ends_at' => now()->addDays(6),
        ]);

        $this->assertNull($this->fetch("/api/v1/member/spaces/{$space->id}")->json('data.details.sale'));
    }

    /** A discount under one percent is announced by its amount, never as "0%". */
    public function test_a_tiny_discount_is_announced_by_its_amount(): void
    {
        $plan = Plan::factory()->create([
            'price_points' => 1000,
            'sale_price_points' => 995,
            'sale_starts_at' => now()->subHour(),
            'sale_ends_at' => now()->addDays(2),
            'is_active' => true,
            'is_public' => true,
            'is_subscription' => true,
        ]);

        $sale = $this->fetch("/api/v1/member/packages/{$plan->id}")->json('data.sale');

        $this->assertSame(5, $sale['discount_points']);
        $this->assertSame(0, $sale['discount_percent']);
        $this->assertSame(__('api.package.sale_discount_amount', ['amount' => $sale['discount_amount_label']], 'en'), $sale['discount_label']);
    }

    public function test_a_package_on_sale_shows_its_sale(): void
    {
        $plan = Plan::factory()->create([
            'price_points' => 120,
            'sale_price_points' => 100,
            'sale_starts_at' => now()->subHour(),
            'sale_ends_at' => now()->addDays(2),
            'is_active' => true,
            'is_public' => true,
            'is_subscription' => true,
        ]);

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
}
