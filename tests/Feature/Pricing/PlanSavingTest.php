<?php

namespace Tests\Feature\Pricing;

use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponLedger;
use App\Domain\Pricing\Services\PlanSavingCalculator;
use App\Support\Presenters\PriceLabelPresenter;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Pricing\Concerns\MakesOffers;
use Tests\TestCase;

/**
 * A plan's built-in saving: a reference (a plan, or a space's hourly rate)
 * times units, against the plan's own price. It is shown and recorded,
 * never taken off the price, and it is not an offer
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
 */
class PlanSavingTest extends TestCase
{
    use MakesOffers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00', 'Asia/Damascus')->utc());
    }

    private function calculator(): PlanSavingCalculator
    {
        return app(PlanSavingCalculator::class);
    }

    private function monthly(): Plan
    {
        return Plan::factory()->create(['price_points' => 125, 'is_active' => true, 'is_public' => true, 'is_subscription' => true]);
    }

    private function yearly(Plan $monthly): Plan
    {
        return Plan::factory()->create([
            'price_points' => 900,
            'reference_plan_id' => $monthly->id,
            'reference_units' => 12,
            'is_active' => true,
            'is_public' => true,
            'is_subscription' => true,
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    private function member(int $points = 0): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => ['en' => 'Yearly', 'ar' => 'سنوي'],
            'is_subscription' => true,
            'price_points' => 900,
            'duration_days' => 365,
            'is_active' => true,
        ], $overrides);
    }

    public function test_a_yearly_plan_saves_against_twelve_monthly_ones(): void
    {
        $saving = $this->calculator()->for($this->yearly($this->monthly()));

        $this->assertSame('plan', $saving->comparedTo);
        $this->assertSame(12, $saving->units);
        $this->assertSame(1500, $saving->referencePoints);
        $this->assertSame(600, $saving->savingPoints);
        $this->assertSame(40, $saving->savingPercent);
        $this->assertSame('75.00', $saving->perUnitPoints);
    }

    public function test_an_hours_bundle_saves_against_the_hourly_rate(): void
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 2]);
        $bundle = Plan::factory()->create(['price_points' => 40, 'reference_space_id' => $space->id, 'reference_units' => 25]);

        $saving = $this->calculator()->for($bundle);

        $this->assertSame('space', $saving->comparedTo);
        $this->assertSame(50, $saving->referencePoints);
        $this->assertSame(10, $saving->savingPoints);
        $this->assertSame(20, $saving->savingPercent);
        $this->assertSame('1.60', $saving->perUnitPoints);
    }

    /** The saving as a sentence beside the badge, counted in the reference's unit. */
    public function test_the_saving_is_described_in_full(): void
    {
        $saving = $this->calculator()->for($this->yearly($this->monthly()));
        $prices = app(PriceLabelPresenter::class);

        $this->assertSame(
            "Get 12 months for 90 $ (regular price 150 $) — save 40% (600 pts). That's 75 pts/mo.",
            $prices->saving($saving, null, 'en')['saving_description'],
        );
        $this->assertSame(
            'احصل على 12 شهراً بـ 90 $ بدلاً من 150 $ — وفّر 40% (600 نقطة). أي 75 نقطة شهرياً.',
            $prices->saving($saving, null, 'ar')['saving_description'],
        );
        $this->assertSame(__('api.package.saving_percent', ['percent' => 40], 'en'), $prices->saving($saving, null, 'en')['saving_label']);
    }

    public function test_an_hours_bundle_is_described_in_hours(): void
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 15]);
        $bundle = Plan::factory()->create(['price_points' => 300, 'reference_space_id' => $space->id, 'reference_units' => 25]);

        $this->assertSame(
            "Get 25 hours for 30 $ (regular price 37.50 $) — save 20% (75 pts). That's 12 pts/hour.",
            app(PriceLabelPresenter::class)->saving($this->calculator()->for($bundle), null, 'en')['saving_description'],
        );
        $this->assertSame(
            'احصل على 25 ساعة بـ 30 $ بدلاً من 37.50 $ — وفّر 20% (75 نقطة). أي 12 نقطة للساعة.',
            app(PriceLabelPresenter::class)->saving($this->calculator()->for($bundle), null, 'ar')['saving_description'],
        );
    }

    /** A day pass compared with hours of the space is a full day, not "8 hours". */
    public function test_a_day_pass_is_described_as_a_full_day(): void
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 15]);
        $daily = Plan::factory()->create(['price_points' => 60, 'duration_days' => 1, 'reference_space_id' => $space->id, 'reference_units' => 8]);
        $saving = $this->calculator()->for($daily);

        $this->assertSame(
            'Get a full day for 6 $ (regular price 12 $ for 8 hours) — save 50% (60 pts).',
            app(PriceLabelPresenter::class)->saving($saving, null, 'en')['saving_description'],
        );
        $this->assertSame(
            'احصل على يوم كامل بـ 6 $ بدلاً من 12 $ لـ 8 ساعات — وفّر 50% (60 نقطة).',
            app(PriceLabelPresenter::class)->saving($saving, null, 'ar')['saving_description'],
        );
    }

    public function test_a_period_that_is_not_a_day_week_or_month_is_named_by_its_plan(): void
    {
        $quarter = Plan::factory()->create(['price_points' => 300, 'duration_days' => 90, 'name' => ['en' => 'Quarter', 'ar' => 'ربع سنوي']]);
        $plan = Plan::factory()->create(['price_points' => 1000, 'reference_plan_id' => $quarter->id, 'reference_units' => 4]);

        $this->assertSame(
            "Get it for 100 $ (regular price 120 $) — save 16% (200 pts). That's 250 pts per Quarter.",
            app(PriceLabelPresenter::class)->saving($this->calculator()->for($plan), null, 'en')['saving_description'],
        );
    }

    public function test_the_percent_rounds_down(): void
    {
        $reference = Plan::factory()->create(['price_points' => 100]);
        $plan = Plan::factory()->create(['price_points' => 200, 'reference_plan_id' => $reference->id, 'reference_units' => 3]);

        $this->assertSame(33, $this->calculator()->for($plan)->savingPercent);
    }

    public function test_no_reference_or_no_saving_is_null(): void
    {
        $this->assertNull($this->calculator()->for($this->monthly()));

        $reference = Plan::factory()->create(['price_points' => 100]);
        $noCheaper = Plan::factory()->create(['price_points' => 300, 'reference_plan_id' => $reference->id, 'reference_units' => 3]);
        $this->assertNull($this->calculator()->for($noCheaper));
    }

    /** E7: the reference's list price, never its offer price. */
    public function test_the_reference_list_price_is_used_never_its_offer_price(): void
    {
        $monthly = $this->monthly();
        $this->offerOnPlan($monthly, 60);

        $this->assertSame(1500, $this->calculator()->for($this->yearly($monthly))->referencePoints);
    }

    public function test_admin_sets_and_clears_a_reference(): void
    {
        $monthly = $this->monthly();
        $yearly = Plan::factory()->create(['price_points' => 900]);
        $this->admin();

        $this->putJson("/api/v1/admin/plans/{$yearly->id}", $this->payload(['reference_plan_id' => $monthly->id, 'reference_units' => 12]))->assertOk();

        $this->getJson("/api/v1/admin/plans/{$yearly->id}")
            ->assertOk()
            ->assertJsonPath('data.reference_plan_id', $monthly->id)
            ->assertJsonPath('data.reference_units', 12)
            ->assertJsonPath('data.saving.saving_percent', 40);

        $this->putJson("/api/v1/admin/plans/{$yearly->id}", $this->payload(['reference_plan_id' => null, 'reference_units' => null]))->assertOk();

        $this->getJson("/api/v1/admin/plans/{$yearly->id}")->assertJsonPath('data.saving', null);
    }

    public function test_a_reference_must_be_exactly_one_kind_with_units(): void
    {
        $monthly = $this->monthly();
        $space = Space::factory()->room()->create(['hourly_rate_points' => 2]);
        $this->admin();

        $this->postJson('/api/v1/admin/plans', $this->payload(['reference_plan_id' => $monthly->id, 'reference_space_id' => $space->id, 'reference_units' => 12]))
            ->assertJsonValidationErrors('reference_plan_id');
        $this->postJson('/api/v1/admin/plans', $this->payload(['reference_plan_id' => $monthly->id]))
            ->assertJsonValidationErrors('reference_units');
        $this->postJson('/api/v1/admin/plans', $this->payload(['reference_units' => 12]))
            ->assertJsonValidationErrors('reference_units');
        $this->postJson('/api/v1/admin/plans', $this->payload(['reference_space_id' => $space->id, 'reference_units' => 0]))
            ->assertJsonValidationErrors('reference_units');
    }

    /** Exactly one reference: one sent beside one stored is refused, not stored twice. */
    public function test_a_reference_sent_beside_a_stored_one_of_the_other_kind_is_refused(): void
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 2]);
        $plan = Plan::factory()->create(['price_points' => 900, 'reference_space_id' => $space->id, 'reference_units' => 25]);
        $monthly = $this->monthly();
        $this->admin();

        $this->putJson("/api/v1/admin/plans/{$plan->id}", $this->payload(['reference_plan_id' => $monthly->id, 'reference_units' => 12]))
            ->assertJsonValidationErrors('reference_plan_id');

        $this->assertNull($plan->fresh()->reference_plan_id);
    }

    public function test_a_plan_cannot_reference_itself(): void
    {
        $plan = Plan::factory()->create(['price_points' => 900]);
        $this->admin();

        $this->putJson("/api/v1/admin/plans/{$plan->id}", $this->payload(['reference_plan_id' => $plan->id, 'reference_units' => 12]))
            ->assertJsonValidationErrors('reference_plan_id');
    }

    /** Both blocks at once: a plan with a saving and a live offer. */
    public function test_the_package_shows_its_saving_beside_its_offer(): void
    {
        $yearly = $this->yearly($this->monthly());
        $this->offerOnPlan($yearly, 810);
        $this->member();

        $data = $this->withHeader('lang', 'en')->getJson("/api/v1/member/packages/{$yearly->id}")->assertOk()->json('data');

        $this->assertSame(810, $data['sale']['points']);
        $this->assertSame('plan', $data['saving']['compared_to']);
        $this->assertSame(1500, $data['saving']['reference_points']);
        $this->assertSame(600, $data['saving']['saving_points']);
        $this->assertSame(40, $data['saving']['saving_percent']);
        $this->assertSame(__('api.package.saving_percent', ['percent' => 40], 'en'), $data['saving']['saving_label']);
        $this->assertNotEmpty($data['saving']['per_unit_label']);
        $this->assertNotEmpty($data['saving']['reference_label']);
    }

    public function test_a_package_without_a_reference_has_no_saving(): void
    {
        $monthly = $this->monthly();
        $this->member();

        $this->withHeader('lang', 'en')->getJson("/api/v1/member/packages/{$monthly->id}")->assertOk()->assertJsonPath('data.saving', null);
    }

    public function test_a_plan_purchase_records_its_saving_in_the_ledger(): void
    {
        $yearly = $this->yearly($this->monthly());
        $this->member(1000);

        $this->postJson('/api/v1/member/memberships', ['plan_id' => $yearly->id])->assertCreated();

        $payment = Payment::sole();
        $this->assertSame(900, $payment->amount_points);
        $row = app(CouponLedger::class)->rowsFor($payment)->firstWhere('kind.value', 'duration_saving');
        $this->assertNotNull($row);
        $this->assertSame(1500, $row->base_points);
        $this->assertSame(600, $row->points);
        $this->assertSame(40, $row->percent);
        $this->assertSame(12, $row->terms['units']);
    }

    /** A saving is not an offer: a coupon that does not combine with offers still applies. */
    public function test_a_non_combinable_coupon_is_allowed_on_a_plan_with_a_saving(): void
    {
        $yearly = $this->yearly($this->monthly());
        Coupon::factory()->percentage(10)->create(['code' => 'SOLO', 'combinable_with_offers' => false]);
        $this->member(1000);

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/memberships', ['plan_id' => $yearly->id, 'coupon_code' => 'SOLO'])
            ->assertCreated()
            ->assertJsonPath('coupon.applied', true);

        $this->assertSame(810, Payment::sole()->amount_points);
    }
}
