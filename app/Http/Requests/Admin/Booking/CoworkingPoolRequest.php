<?php

namespace App\Http\Requests\Admin\Booking;

use App\Http\Requests\Concerns\InterpretsLocalTimes;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The range to read a branch's co-working pool over, in the district's
 * wall-clock; from now to the end of today when left out. At most 62 days —
 * a two-month look ahead, the longest a subscription is sold for.
 */
class CoworkingPoolRequest extends FormRequest
{
    use InterpretsLocalTimes;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->interpretLocalTimes(['from', 'to']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after:from'],
        ];
    }
}
