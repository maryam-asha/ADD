<?php

namespace Database\Seeders;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanModule;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Membership\Services\PlanHourCoverageService;
use App\Domain\Pricing\Models\CouponTarget;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The real plan catalog (price sheet of 2026-10-08), replacing the demo
 * plans this seeder used to create — those live on as a test fixture,
 * tests/Support/LegacyPlanCatalogSeeder.php.
 *
 * Prices are the sheet's USD × 10 (`point.rate_usd` = 0.10). Each plan is
 * matched by its English name, so a re-run updates it in place; any plan not
 * in this list is deleted, with its memberships and coupon targets — a
 * development reset, refused in production when a plan to delete was ever
 * subscribed to.
 *
 * How time is covered: a co-working session is absorbed only by a plan with
 * an access window and a `space-hours` grant (PlanHourCoverageService). So
 * every plan has both: the hour packages their 25 / 50 hours round the clock,
 * the evening package 17:00–00:00, and the unlimited ones every hour of their
 * period — a quota they cannot run out of.
 *
 * The "current discount" column is not stored: it is each plan's saving
 * against a reference (PlanSavingCalculator) — the months against the same
 * desk's monthly plan, the hour packages and the day against the Hot Desk's
 * hourly rate (the day as 8 hours), the week against seven days.
 */
class PlanSeeder extends Seeder
{
    private const HOT_DESK = 'flexi_desk';

    private const FULL_DAY = ['00:00', '00:00'];

    private const EVENING = ['17:00', '00:00'];

    public function run(): void
    {
        $hotDeskSpace = Space::query()->where('space_type', SpaceType::CoSpace)->orderBy('id')->first();

        // The Hot Desk's hourly rate is the sheet's 1.5 USD — what the hour
        // packages and the day pass are compared against.
        $hotDeskSpace?->forceFill(['hourly_rate_points' => 15])->save();

        $kept = [];

        foreach ($this->catalog() as $key => $data) {
            $kept[$key] = $this->upsert($data, $kept, $hotDeskSpace);
        }

        $this->deleteAllBut(collect($kept)->map->id->values()->all());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function catalog(): array
    {
        $hot = fn (string $en, string $ar) => ['en' => "Hot Desk — {$en}", 'ar' => "مكتب مرن — {$ar}"];
        $dedicated = fn (string $en, string $ar) => ['en' => "Dedicated Desk — {$en}", 'ar' => "مكتب مخصص — {$ar}"];

        return [
            'hours_25' => [
                'module' => self::HOT_DESK, 'name' => ['en' => '25 Hours a Month', 'ar' => 'باقة ساعات شهرية 25 ساعة'],
                'price' => 300, 'days' => 30, 'window' => self::FULL_DAY, 'hours' => 25, 'compare' => ['space', 25],
            ],
            'hours_50' => [
                'module' => self::HOT_DESK, 'name' => ['en' => '50 Hours a Month', 'ar' => 'باقة ساعات شهرية 50 ساعة'],
                'price' => 500, 'days' => 30, 'window' => self::FULL_DAY, 'hours' => 50, 'compare' => ['space', 50],
            ],
            'hot_daily' => [
                'module' => self::HOT_DESK, 'name' => $hot('Daily', 'يومي'),
                'price' => 60, 'days' => 1, 'window' => self::FULL_DAY, 'compare' => ['space', 8],
            ],
            'hot_weekly' => [
                'module' => self::HOT_DESK, 'name' => $hot('Weekly', 'أسبوعي'),
                'price' => 350, 'days' => 7, 'window' => self::FULL_DAY, 'compare' => ['hot_daily', 7],
            ],
            'hot_monthly' => [
                'module' => self::HOT_DESK, 'name' => $hot('Monthly', 'شهري'),
                'price' => 1250, 'days' => 30, 'window' => self::FULL_DAY,
            ],
            'hot_evening' => [
                'module' => self::HOT_DESK, 'name' => $hot('Evening Monthly (5 PM – 12 AM)', 'شهري مسائي من 5 إلى 12 ليلاً'),
                'price' => 500, 'days' => 30, 'window' => self::EVENING,
            ],
            'hot_3_months' => [
                'module' => self::HOT_DESK, 'name' => $hot('3 Months', '3 أشهر'),
                'price' => 3000, 'days' => 90, 'window' => self::FULL_DAY, 'compare' => ['hot_monthly', 3],
            ],
            'hot_6_months' => [
                'module' => self::HOT_DESK, 'name' => $hot('6 Months', '6 أشهر'),
                'price' => 5500, 'days' => 180, 'window' => self::FULL_DAY, 'compare' => ['hot_monthly', 6],
            ],
            'hot_12_months' => [
                'module' => self::HOT_DESK, 'name' => $hot('12 Months', '12 شهراً'),
                'price' => 9000, 'days' => 360, 'window' => self::FULL_DAY, 'compare' => ['hot_monthly', 12],
            ],
            'dedicated_monthly' => [
                'module' => PlanModule::DEDICATED_DESK, 'name' => $dedicated('Monthly', 'شهري'),
                'price' => 1750, 'days' => 30, 'window' => self::FULL_DAY,
            ],
            'dedicated_3_months' => [
                'module' => PlanModule::DEDICATED_DESK, 'name' => $dedicated('3 Months', '3 أشهر'),
                'price' => 4500, 'days' => 90, 'window' => self::FULL_DAY, 'compare' => ['dedicated_monthly', 3],
            ],
            'dedicated_6_months' => [
                'module' => PlanModule::DEDICATED_DESK, 'name' => $dedicated('6 Months', '6 أشهر'),
                'price' => 8500, 'days' => 180, 'window' => self::FULL_DAY, 'compare' => ['dedicated_monthly', 6],
            ],
            'dedicated_12_months' => [
                'module' => PlanModule::DEDICATED_DESK, 'name' => $dedicated('12 Months', '12 شهراً'),
                'price' => 15000, 'days' => 360, 'window' => self::FULL_DAY, 'compare' => ['dedicated_monthly', 12],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, Plan>  $seeded  the plans written so far, by key — a reference is always seeded before the plan naming it
     */
    private function upsert(array $data, array $seeded, ?Space $hotDeskSpace): Plan
    {
        [$compareTo, $units] = $data['compare'] ?? [null, null];
        [$windowStart, $windowEnd] = $data['window'];

        $plan = Plan::query()->where('name->en', $data['name']['en'])->first() ?? new Plan;
        $plan->fill([
            'plan_module_id' => PlanModule::query()->where('slug', $data['module'])->value('id'),
            'name' => $data['name'],
            'subtitle' => null,
            'is_subscription' => true,
            'price_points' => $data['price'],
            'duration_days' => $data['days'],
            'access_window_start' => $windowStart,
            'access_window_end' => $windowEnd,
            'reference_plan_id' => $compareTo !== null && $compareTo !== 'space' ? $seeded[$compareTo]->id : null,
            'reference_space_id' => $compareTo === 'space' ? $hotDeskSpace?->id : null,
            'reference_units' => $compareTo === 'space' && $hotDeskSpace === null ? null : $units,
            'is_active' => true,
            'is_public' => true,
            'order' => count($seeded) + 1,
        ])->save();

        $hours = $data['hours'] ?? $this->hoursIn($data['days'], $windowStart, $windowEnd);

        $plan->balances()->delete();
        $plan->discounts()->delete();

        $plan->resourceGrants()->delete();
        $hourType = ResourceType::query()->where('slug', PlanHourCoverageService::RESOURCE_TYPE_SLUG)->first();

        if ($hourType !== null) {
            $plan->resourceGrants()->create([
                'resource_type_id' => $hourType->id,
                'quantity' => $hours,
                'description' => ['en' => "{$hours} desk hours", 'ar' => "{$hours} ساعة مكتب"],
                'order' => 1,
            ]);
        }

        $plan->features()->delete();
        $plan->features()->createMany($this->features($data, $hours));

        return $plan;
    }

    /**
     * Every hour the window opens over the period: a quota the plan cannot
     * run out of.
     */
    private function hoursIn(int $days, string $start, string $end): int
    {
        $minutes = fn (string $time) => ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
        $open = $minutes($end) - $minutes($start);

        return $days * intdiv($open > 0 ? $open : $open + 24 * 60, 60);
    }

    /**
     * The basics only — the desk, how long or how many hours, the window, the
     * internet. No café or printing balances and no room discounts until they
     * are priced.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function features(array $data, int $hours): array
    {
        $dedicated = $data['module'] === PlanModule::DEDICATED_DESK;
        $features = [[
            'name' => $dedicated
                ? ['en' => 'Your own Dedicated Desk', 'ar' => 'مكتب مخصص لك']
                : ['en' => 'Access to a Hot Desk', 'ar' => 'وصول إلى مكتب مرن'],
            'value' => null,
            'icon' => $dedicated ? 'briefcase' : 'monitor',
        ]];

        $features[] = isset($data['hours'])
            ? ['name' => ['en' => "{$hours} hours a month", 'ar' => "{$hours} ساعة شهرياً"], 'value' => ['en' => "{$hours} Hours", 'ar' => "{$hours} ساعة"], 'icon' => 'clock']
            : ['name' => ['en' => "{$data['days']} days unlimited", 'ar' => "{$data['days']} يوماً بلا حدود"], 'value' => ['en' => "{$data['days']} Days", 'ar' => "{$data['days']} يوماً"], 'icon' => 'calendar'];

        if ($data['window'] === self::EVENING) {
            $features[] = ['name' => ['en' => 'Evenings from 5 PM to midnight', 'ar' => 'مساءً من 5 حتى 12 ليلاً'], 'value' => ['en' => '5 PM – 12 AM', 'ar' => '5 – 12 ليلاً'], 'icon' => 'clock'];
        }

        $features[] = ['name' => ['en' => 'Internet Access', 'ar' => 'إنترنت عالي السرعة'], 'value' => null, 'icon' => 'wifi'];

        return array_map(
            fn (array $feature, int $i) => $feature + ['is_included' => true, 'order' => $i + 1],
            $features,
            array_keys($features),
        );
    }

    /**
     * @param  list<int>  $keep
     */
    private function deleteAllBut(array $keep): void
    {
        $stale = Plan::query()->whereNotIn('id', $keep)->pluck('id');

        if ($stale->isEmpty()) {
            return;
        }

        if (app()->isProduction() && Membership::query()->whereIn('plan_id', $stale)->exists()) {
            throw new RuntimeException('PlanSeeder will not delete plans members subscribed to in production: '.$stale->implode(', '));
        }

        DB::transaction(function () use ($stale) {
            // Coupon targets restrict a plan's delete; memberships hold a
            // plain FK. Everything under a membership cascades or is nulled.
            CouponTarget::query()->whereIn('plan_id', $stale)->delete();
            Plan::query()->whereIn('reference_plan_id', $stale)->update(['reference_plan_id' => null]);
            Membership::query()->whereIn('plan_id', $stale)->delete();
            Plan::query()->whereIn('id', $stale)->delete();
        });
    }
}
