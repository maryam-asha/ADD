<?php

namespace App\Http\Requests\Member;

use App\Domain\Pricing\Enums\PricingPurpose;
use App\Http\Requests\Concerns\InterpretsLocalTimes;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A code and what it would be used on: a plan; a booking window (a space
 * and its times) or an unpaid booking; a closed co-working session; an
 * event or an unpaid registration for one.
 */
class PreviewCouponRequest extends FormRequest
{
    use InterpretsLocalTimes;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->interpretLocalTimes(['start_at', 'end_at']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40'],
            'purpose' => ['required', Rule::enum(PricingPurpose::class)],
            // Only a plan the member could buy — active and public, as both
            // purchase paths require.
            'plan_id' => [
                'required_if:purpose,'.PricingPurpose::PlanPurchase->value,
                'nullable',
                'integer',
                Rule::exists('plans', 'id')->where('is_active', true)->where('is_public', true),
            ],
            'payable_id' => ['required_if:purpose,'.PricingPurpose::CoworkingSession->value, 'nullable', 'integer'],
            'space_id' => ['nullable', 'integer', 'exists:spaces,id', 'required_with:start_at,end_at'],
            'start_at' => ['nullable', 'date', 'required_with:space_id'],
            'end_at' => ['nullable', 'date', 'after:start_at', 'required_with:space_id'],
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
        ];
    }

    /**
     * A booking is a window or an unpaid booking; a registration an event or
     * an unpaid registration — one of the two is needed.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $missing = match ($this->input('purpose')) {
                PricingPurpose::Booking->value => ! $this->filled('payable_id') && ! $this->filled('space_id') ? 'space_id' : null,
                PricingPurpose::EventRegistration->value => ! $this->filled('payable_id') && ! $this->filled('event_id') ? 'event_id' : null,
                default => null,
            };

            if ($missing !== null) {
                $validator->errors()->add($missing, __('validation.required', ['attribute' => $missing]));
            }
        });
    }

    public function purpose(): PricingPurpose
    {
        return PricingPurpose::from($this->validated('purpose'));
    }
}
