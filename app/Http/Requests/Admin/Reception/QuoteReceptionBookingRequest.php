<?php

namespace App\Http\Requests\Admin\Reception;

use App\Http\Requests\Concerns\InterpretsLocalTimes;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a desk booking would cost, before it is made: the same member, space
 * and district wall-clock times StoreReceptionBookingRequest takes, without
 * the payment — the price is what the payment is chosen against.
 */
class QuoteReceptionBookingRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'space_id' => ['required', 'integer', 'exists:spaces,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
        ];
    }
}
