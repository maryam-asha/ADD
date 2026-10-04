<?php

namespace App\Http\Requests\Admin\Reception;

use App\Http\Requests\Concerns\InterpretsLocalTimes;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmSessionEndRequest extends FormRequest
{
    use InterpretsLocalTimes;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->interpretLocalTimes(['checked_out_at']);
    }

    /**
     * Omitted means "when the member pressed end" — the default.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'checked_out_at' => ['nullable', 'date', 'before_or_equal:now'],
            // The desk has the money for a request the member chose to pay
            // in cash: the session is closed and settled in one step.
            'cash_collected' => ['nullable', 'boolean'],
        ];
    }
}
