<?php

namespace Database\Seeders;

use App\Domain\Experience\Models\Event;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Enums\CouponRevisionAction;
use App\Domain\Pricing\Enums\CouponSource;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo offers and coupons covering every case the pricing pipeline knows
 * (docs/decisions/pricing-offers-and-coupons.md,
 * docs/decisions/automatic-offers-savings-discount-ledger.md):
 *
 * Offers (automatic, no code) — one on everything; one per family of item
 * (a space type, one space at a fixed rate, a plan with a usage limit); one
 * for new members only; one scheduled to start next week.
 *
 * Coupons (typed codes) — a public code; one that stacks on offers with a
 * minimum price; one given to named members; one for a company's members;
 * one for new members; and three that are refused, each for its own reason
 * (not started, expired, switched off).
 *
 * Local development only, and re-runnable: a coupon is found by its code,
 * an offer by its English name, and one that exists is left as it is — so
 * an admin's edits survive a second run. Items are looked up in what the
 * other seeders made; a case whose item is missing is skipped with a note.
 *
 * Run manually: php artisan db:seed --class=OffersAndCouponsDemoSeeder
 */
class OffersAndCouponsDemoSeeder extends Seeder
{
    private CarbonImmutable $now;

    public function run(CouponHistory $history): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('OffersAndCouponsDemoSeeder writes public demo codes; it never runs in production.');
        }

        $this->now = CarbonImmutable::now();
        $admin = User::role('admin')->orderBy('id')->first();

        foreach ([...$this->offers(), ...$this->coupons()] as $spec) {
            if ($spec === null) {
                continue;
            }

            DB::transaction(fn () => $this->seed($spec, $history, $admin));
        }
    }

    /**
     * @return list<array<string, mixed>|null>
     */
    private function offers(): array
    {
        $business = Space::where('space_type', SpaceType::Business)->orderBy('id')->first();
        $plan = $this->plan();

        return [
            // On everything a coupon can be spent on: every space and plan
            // shows its `sale` block. Not events — they take only what is
            // targeted at them.
            [
                'name' => ['en' => 'Opening offer', 'ar' => 'عرض الافتتاح'],
                'description' => ['en' => '10% off everything for a month.', 'ar' => 'خصم 10% على كل شيء لمدة شهر.'],
                'discount_type' => CouponDiscountType::Percentage,
                'discount_value' => 10,
                'ends_at' => $this->now->addDays(30),
            ],
            // A family of items: every room, booked or used as a session.
            [
                'name' => ['en' => 'Meeting rooms week', 'ar' => 'أسبوع غرف الاجتماعات'],
                'discount_type' => CouponDiscountType::Percentage,
                'discount_value' => 20,
                'ends_at' => $this->now->addDays(7),
                'targets' => [
                    ['purpose' => PricingPurpose::Booking, 'space_type' => SpaceType::Room->value],
                    ['purpose' => PricingPurpose::CoworkingSession, 'space_type' => SpaceType::Room->value],
                ],
            ],
            // A fixed rate on one space — only below its normal rate.
            $business === null ? $this->skip('Business suite rate offer', 'no business space') : [
                'name' => ['en' => 'Business suite rate', 'ar' => 'سعر مكتب الأعمال'],
                'discount_type' => CouponDiscountType::FixedPrice,
                'discount_value' => max(1, intdiv((int) $business->hourly_rate_points * 80, 100)),
                'ends_at' => $this->now->addDays(14),
                'targets' => [['purpose' => PricingPurpose::Booking, 'space_id' => $business->id]],
            ],
            // One plan, for the first fifty purchases.
            $plan === null ? $this->skip('Package offer', 'no active public plan') : [
                'name' => ['en' => 'First fifty', 'ar' => 'لأول خمسين مشترك'],
                'discount_type' => CouponDiscountType::AmountOff,
                'discount_value' => min(100, (int) $plan->price_points - 1),
                'usage_limit' => 50,
                'targets' => [['purpose' => PricingPurpose::PlanPurchase, 'plan_id' => $plan->id]],
            ],
            // Only for a member's first purchase: others never see it.
            [
                'name' => ['en' => 'New member welcome', 'ar' => 'ترحيب العضو الجديد'],
                'discount_type' => CouponDiscountType::Percentage,
                'discount_value' => 15,
                'new_members_only' => true,
                'targets' => [['purpose' => PricingPurpose::PlanPurchase]],
            ],
            // Scheduled: not live yet, so not shown and not charged.
            [
                'name' => ['en' => 'Events season', 'ar' => 'موسم الفعاليات'],
                'discount_type' => CouponDiscountType::Percentage,
                'discount_value' => 30,
                'starts_at' => $this->now->addDays(7),
                'ends_at' => $this->now->addDays(21),
                'targets' => [['purpose' => PricingPurpose::EventRegistration]],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>|null>
     */
    private function coupons(): array
    {
        $members = User::role('member')->orderBy('id')->limit(3)->get();
        $company = Company::orderBy('id')->first();

        return [
            // Public: given to no one, so handed out outside the app and
            // never listed in "my coupons" — but anyone may type it.
            [
                'code' => 'WELCOME10',
                'name' => ['en' => 'Welcome', 'ar' => 'أهلاً بك'],
                'discount_type' => CouponDiscountType::Percentage,
                'discount_value' => 10,
                'max_discount_points' => 100,
            ],
            // Stacks on an offer, with a minimum price and a total limit.
            [
                'code' => 'ROOM50',
                'name' => ['en' => '50 points off a room', 'ar' => '50 نقطة على الغرف'],
                'discount_type' => CouponDiscountType::AmountOff,
                'discount_value' => 50,
                'min_amount_points' => 200,
                'usage_limit' => 100,
                'combinable_with_offers' => true,
                'targets' => [['purpose' => PricingPurpose::Booking, 'space_type' => SpaceType::Room->value]],
            ],
            // Named members: listed in their "my coupons", refused to anyone else.
            $members->isEmpty() ? $this->skip('VIP25', 'no members') : [
                'code' => 'VIP25',
                'name' => ['en' => 'VIP packages', 'ar' => 'باقات كبار الأعضاء'],
                'discount_type' => CouponDiscountType::Percentage,
                'discount_value' => 25,
                'per_user_limit' => 2,
                'ends_at' => $this->now->addDays(60),
                'targets' => [['purpose' => PricingPurpose::PlanPurchase]],
                'users' => $members->modelKeys(),
            ],
            // A company's members, on time in a space.
            $company === null ? $this->skip('TEAM20', 'no company') : [
                'code' => 'TEAM20',
                'name' => ['en' => 'Team discount', 'ar' => 'خصم الفريق'],
                'discount_type' => CouponDiscountType::Percentage,
                'discount_value' => 20,
                'per_user_limit' => null,
                'company_id' => $company->id,
                'targets' => [
                    ['purpose' => PricingPurpose::Booking],
                    ['purpose' => PricingPurpose::CoworkingSession],
                ],
            ],
            [
                'code' => 'FIRSTPLAN',
                'name' => ['en' => 'First package', 'ar' => 'الباقة الأولى'],
                'discount_type' => CouponDiscountType::Percentage,
                'discount_value' => 30,
                'max_discount_points' => 150,
                'new_members_only' => true,
                'targets' => [['purpose' => PricingPurpose::PlanPurchase]],
            ],
            // Refused, each with its own reason.
            [
                'code' => 'SOON15',
                'name' => ['en' => 'Coming soon', 'ar' => 'قريباً'],
                'discount_type' => CouponDiscountType::Percentage,
                'discount_value' => 15,
                'starts_at' => $this->now->addDays(3),
                'targets' => [['purpose' => PricingPurpose::EventRegistration]],
            ],
            [
                'code' => 'EXPIRED20',
                'name' => ['en' => 'Summer (ended)', 'ar' => 'الصيف (منتهٍ)'],
                'discount_type' => CouponDiscountType::Percentage,
                'discount_value' => 20,
                'starts_at' => $this->now->subDays(30),
                'ends_at' => $this->now->subDay(),
            ],
            [
                'code' => 'PAUSED',
                'name' => ['en' => 'Paused', 'ar' => 'موقوف'],
                'discount_type' => CouponDiscountType::AmountOff,
                'discount_value' => 30,
                'is_active' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function seed(array $spec, CouponHistory $history, ?User $admin): void
    {
        $isOffer = ! isset($spec['code']);
        $existing = $isOffer
            ? Coupon::where('is_automatic', true)->where('name->en', $spec['name']['en'])->first()
            : Coupon::where('code', Coupon::normalizeCode($spec['code']))->first();

        if ($existing !== null) {
            return;
        }

        $targets = $spec['targets'] ?? [];
        $users = $spec['users'] ?? [];
        unset($spec['targets'], $spec['users']);

        $coupon = Coupon::create(array_merge([
            'is_automatic' => $isOffer,
            'source' => CouponSource::Admin,
            // As the dashboard creates them: one use per member for a coupon,
            // none for an offer; no stacking unless asked.
            'per_user_limit' => $isOffer ? null : 1,
            'combinable_with_offers' => false,
            'is_active' => true,
            'created_by' => $admin?->id,
        ], $spec));

        foreach ($targets as $target) {
            $coupon->targets()->create($target);
        }

        $coupon->users()->sync($users);
        $history->record($coupon, CouponRevisionAction::Created, $admin);
    }

    private function plan(): ?Plan
    {
        return Plan::where('is_active', true)->where('is_public', true)->where('price_points', '>', 1)->orderBy('id')->first();
    }

    private function skip(string $what, string $why): null
    {
        $this->command?->warn("Skipped {$what}: {$why}.");

        return null;
    }
}
