<?php

namespace App\Http\Requests\Member;

use App\Domain\Experience\Models\Event;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\CouponTarget;
use App\Domain\Pricing\ValueObjects\PricingTarget;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Optionally, what the member is about to buy: a purpose, and the item it
 * is for — a space for a booking or a co-working session, a plan, an
 * event. An item needs its purpose, and must be one that purpose names.
 */
class ListMemberCouponsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'purpose' => ['nullable', 'required_with:space_id,plan_id,event_id', Rule::enum(PricingPurpose::class)],
            'space_id' => ['nullable', 'integer', 'exists:spaces,id'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $purpose = PricingPurpose::tryFrom((string) $this->input('purpose'));

            if ($purpose === null) {
                return;
            }

            foreach (['space_id', 'plan_id', 'event_id'] as $item) {
                $allowed = $item === 'space_id' ? ['space_id', 'space_type'] : [$item];

                if ($this->filled($item) && array_intersect($allowed, CouponTarget::itemsFor($purpose)) === []) {
                    $validator->errors()->add($item, __('validation.prohibited', ['attribute' => $item]));
                }
            }
        });
    }

    /** The space, plan or event asked about, if one was. */
    public function item(): Space|Plan|Event|null
    {
        return match (true) {
            $this->filled('space_id') => Space::findOrFail($this->validated('space_id')),
            $this->filled('plan_id') => Plan::findOrFail($this->validated('plan_id')),
            $this->filled('event_id') => Event::findOrFail($this->validated('event_id')),
            default => null,
        };
    }

    /** What was asked about, or null for every coupon the member has. */
    public function target(): ?PricingTarget
    {
        $purpose = $this->validated('purpose');

        if ($purpose === null) {
            return null;
        }

        $purpose = PricingPurpose::from($purpose);

        return match (true) {
            $this->filled('space_id') => PricingTarget::forSpace($purpose, Space::findOrFail($this->validated('space_id'))),
            $this->filled('plan_id') => PricingTarget::forPlan(Plan::findOrFail($this->validated('plan_id'))),
            $this->filled('event_id') => PricingTarget::forEvent(Event::findOrFail($this->validated('event_id'))),
            default => new PricingTarget($purpose),
        };
    }
}
