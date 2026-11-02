<?php

namespace Add\Hr\Leave\Http\Requests;

use Add\Hr\Leave\Enums\LedgerKind;
use Add\Hr\Support\LocalDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A manual movement: the balance carried in at go-live (opening), a
 * correction (adjustment, either sign), or days paid out (encashment,
 * negative).
 */
class AdjustLeaveBalanceRequest extends FormRequest
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
        $encashment = $this->input('kind') === LedgerKind::Encashment->value;

        return [
            'leave_type_id' => ['required', 'integer', Rule::exists('hr_leave_types', 'id')->where('tracks_balance', true)],
            'kind' => ['required', Rule::in([LedgerKind::Opening->value, LedgerKind::Adjustment->value, LedgerKind::Encashment->value])],
            'amount' => ['required', 'numeric', 'between:-366,366', 'not_in:0', ...($encashment ? ['lt:0'] : [])],
            'effective_on' => ['nullable', 'date', 'before_or_equal:'.LocalDay::today()->toDateString()],
            'period_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'notes' => ['required', 'string', 'max:500'],
        ];
    }
}
