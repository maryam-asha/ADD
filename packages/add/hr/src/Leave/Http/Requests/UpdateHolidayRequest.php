<?php

namespace Add\Hr\Leave\Http\Requests;

use Add\Hr\Support\TranslatableField;

class UpdateHolidayRequest extends StoreHolidayRequest
{
    /**
     * Dates are sent together, so the range is always checked whole.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...TranslatableField::sometimes('name'),
            'starts_on' => ['required_with:ends_on', 'date'],
            'ends_on' => ['required_with:starts_on', 'date', 'after_or_equal:starts_on', 'before_or_equal:'.$this->lastDay()],
            'recurs_annually' => ['sometimes', 'boolean'],
        ];
    }
}
