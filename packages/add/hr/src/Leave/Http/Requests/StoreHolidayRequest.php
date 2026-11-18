<?php

namespace Add\Hr\Leave\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

class StoreHolidayRequest extends FormRequest
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
            ...TranslatableField::rules('name'),
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on', 'before_or_equal:'.$this->lastDay()],
            'recurs_annually' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A holiday spans at most 31 days, so a recurring one stays in its season.
     */
    protected function lastDay(): string
    {
        $start = strtotime((string) $this->input('starts_on')) ?: time();

        return date('Y-m-d', strtotime('+30 days', $start));
    }
}
