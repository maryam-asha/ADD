<?php

namespace App\Http\Requests\Admin\Reception;

use App\Http\Requests\Concerns\InterpretsLocalTimes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reception booking on a member's behalf — the member is named in the body
 * rather than taken from the token, which is the only difference from the
 * member's own StoreBookingRequest. Every availability rule still applies.
 */
class StoreReceptionBookingRequest extends FormRequest
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
            'wallet_owner_type' => ['nullable', 'string', Rule::in(['user', 'company']), 'required_with:wallet_owner_id'],
            'wallet_owner_id' => ['nullable', 'integer', 'required_with:wallet_owner_type'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
