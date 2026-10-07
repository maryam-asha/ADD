<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Models\CouponTarget;
use App\Http\Requests\Concerns\InterpretsLocalTimes;
use App\Support\TranslatableField;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A coupon as the admin writes it
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md) — or
 * an offer, `is_automatic`: no code, and the only kind that may set a
 * `fixed_price`, with targets of one family
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
 *
 * `targets` is what it is for — none: everything a coupon can be spent on;
 * each names a purpose and at most one item that purpose buys. `user_ids`
 * is who may use it — none: anyone. Either, left out on an update, stays as
 * it is. The validity window is local time.
 */
class StoreCouponRequest extends FormRequest
{
    use InterpretsLocalTimes;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Coupon::normalizeCode($this->input('code'))]);
        }

        $this->interpretLocalTimes(['starts_at', 'ends_at']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(
            TranslatableField::rules('name', false),
            TranslatableField::rules('description', false),
            [
                'is_automatic' => ['sometimes', 'boolean'],
                'code' => $this->isAutomatic()
                    ? ['prohibited']
                    : [$this->codeRequirement(), 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', $this->uniqueCode()],
                'discount_type' => ['required', $this->isAutomatic()
                    ? Rule::enum(CouponDiscountType::class)
                    : Rule::enum(CouponDiscountType::class)->except(CouponDiscountType::FixedPrice)],
                'discount_value' => array_filter([
                    'required', 'integer', 'min:1',
                    $this->input('discount_type') === CouponDiscountType::Percentage->value ? 'max:100' : null,
                ]),
                'max_discount_points' => ['nullable', 'integer', 'min:1'],
                'min_amount_points' => ['nullable', 'integer', 'min:0'],
                'combinable_with_offers' => ['sometimes', 'boolean'],
                'usage_limit' => ['nullable', 'integer', 'min:1'],
                'per_user_limit' => ['nullable', 'integer', 'min:1'],
                'new_members_only' => ['sometimes', 'boolean'],
                'company_id' => ['nullable', 'integer', 'exists:companies,id'],
                'starts_at' => ['nullable', 'date'],
                'ends_at' => ['nullable', 'date', 'after:starts_at'],
                'is_active' => ['sometimes', 'boolean'],

                'targets' => ['sometimes', 'array'],
                'targets.*' => ['array'],
                'targets.*.purpose' => ['required', Rule::enum(PricingPurpose::class)],
                'targets.*.plan_id' => ['nullable', 'integer', 'exists:plans,id'],
                'targets.*.space_id' => ['nullable', 'integer', 'exists:spaces,id'],
                'targets.*.space_type' => ['nullable', Rule::enum(SpaceType::class)],
                'targets.*.event_id' => ['nullable', 'integer', 'exists:events,id'],

                'user_ids' => ['sometimes', 'array'],
                'user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            ],
        );
    }

    protected function uniqueCode(): mixed
    {
        return Rule::unique('coupons', 'code');
    }

    /** Whether this is an offer: as sent when created; as stored when changed. */
    public function isAutomatic(): bool
    {
        return $this->boolean('is_automatic');
    }

    protected function codeRequirement(): string
    {
        return 'required';
    }

    /**
     * The coupon's stored targets, for an update that leaves them out.
     *
     * @return list<array<string, mixed>>
     */
    protected function storedTargets(): array
    {
        return [];
    }

    /** The purposes a price means the same thing across. */
    private const FIXED_PRICE_FAMILIES = [
        [PricingPurpose::Booking, PricingPurpose::CoworkingSession],
        [PricingPurpose::PlanPurchase],
        [PricingPurpose::EventRegistration],
    ];

    /**
     * A fixed price is a price per hour for time in a space, or a price for
     * a plan or an event: an offer setting one names at least one target,
     * all of one family.
     */
    private function assertFixedPriceFamily(Validator $validator): void
    {
        $type = $this->input('discount_type');

        if (! $this->isAutomatic() || $type !== CouponDiscountType::FixedPrice->value || $validator->errors()->has('targets')) {
            return;
        }

        $targets = $this->exists('targets') ? (array) $this->input('targets') : $this->storedTargets();
        $purposes = array_values(array_unique(array_filter(array_map(
            fn ($target) => is_array($target) ? PricingPurpose::tryFrom((string) ($target['purpose'] ?? '')) : null,
            $targets,
        )), SORT_REGULAR));

        $oneFamily = $purposes !== [] && collect(self::FIXED_PRICE_FAMILIES)->contains(
            fn (array $family) => collect($purposes)->every(fn (PricingPurpose $purpose) => in_array($purpose, $family, true)),
        );

        if (! $oneFamily) {
            $validator->errors()->add('targets', __('api.admin.offer_fixed_price_family'));
        }
    }

    /**
     * A target names at most one item, and one its purpose buys — a 422
     * here rather than the model's exception later.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->assertFixedPriceFamily($validator);

            foreach ((array) $this->input('targets', []) as $index => $target) {
                $purpose = is_array($target) ? PricingPurpose::tryFrom((string) ($target['purpose'] ?? '')) : null;

                if ($purpose === null) {
                    continue;
                }

                $named = array_keys(array_filter(
                    array_intersect_key($target, array_flip(CouponTarget::ITEM_COLUMNS)),
                    fn ($value) => $value !== null && $value !== '',
                ));

                if (count($named) > 1 || ($named !== [] && ! in_array($named[0], CouponTarget::itemsFor($purpose), true))) {
                    $validator->errors()->add("targets.{$index}", __('api.admin.coupon_target_invalid'));
                }
            }
        });
    }
}
