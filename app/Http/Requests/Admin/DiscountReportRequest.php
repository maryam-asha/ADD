<?php

namespace App\Http\Requests\Admin;

use App\Domain\Pricing\Enums\DiscountKind;
use App\Domain\Pricing\Enums\PricingPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The discount report's filters (DiscountReport): local dates, one kind,
 * one purpose, counted or void.
 */
class DiscountReportRequest extends FormRequest
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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'kind' => ['nullable', Rule::enum(DiscountKind::class)],
            'purpose' => ['nullable', Rule::enum(PricingPurpose::class)],
            'status' => ['nullable', 'in:counted,void'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
