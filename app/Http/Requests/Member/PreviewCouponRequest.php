<?php

namespace App\Http\Requests\Member;

use App\Domain\Pricing\Enums\PricingPurpose;
use App\Http\Requests\Concerns\InterpretsLocalTimes;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * A code, and — optionally — what it would be used on. The code alone is
 * checked for this member; a purpose, or a purpose and an item, also that it
 * applies there; something that can be priced — a plan; a booking window (a
 * space and its times) or an unpaid booking; a closed co-working session; an
 * event or an unpaid registration for one — is priced too.
 *
 * The purpose and its item are those "my coupons" takes, plus a booking
 * window and `payable_id`.
 */
class PreviewCouponRequest extends ListMemberCouponsRequest
{
    use InterpretsLocalTimes;

    protected function prepareForValidation(): void
    {
        $this->interpretLocalTimes(['start_at', 'end_at']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'code' => ['required', 'string', 'max:40'],
            'purpose' => ['nullable', 'required_with:space_id,plan_id,event_id,payable_id', Rule::enum(PricingPurpose::class)],
            // Only a plan the member could buy — active and public, as both
            // purchase paths require.
            'plan_id' => ['nullable', 'integer', Rule::exists('plans', 'id')->where('is_active', true)->where('is_public', true)],
            'payable_id' => ['nullable', 'integer'],
            'space_id' => ['nullable', 'integer', 'exists:spaces,id', 'required_with:start_at,end_at'],
            'start_at' => ['nullable', 'date', 'required_with:end_at'],
            'end_at' => ['nullable', 'date', 'after:start_at', 'required_with:start_at'],
        ]);
    }

    /** Besides the parent's item-for-purpose check: a plan is never already owed. */
    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator) {
            if ($this->filled('payable_id') && $this->input('purpose') === PricingPurpose::PlanPurchase->value) {
                $validator->errors()->add('payable_id', __('validation.prohibited', ['attribute' => 'payable_id']));
            }
        });
    }

    public function purpose(): ?PricingPurpose
    {
        return PricingPurpose::tryFrom((string) $this->validated('purpose'));
    }
}
