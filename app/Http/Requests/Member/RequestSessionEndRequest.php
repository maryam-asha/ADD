<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The member's "end session" press, after the checkout showed them the time
 * and the price: `checkout_token` is that checkout, so the session is closed
 * at exactly what they agreed to. How they pay is chosen only once reception
 * has confirmed, through POST member/coworking-sessions/{type}/{id}/pay
 * (docs/decisions/coworking-self-service-sessions.md).
 */
class RequestSessionEndRequest extends FormRequest
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
            'checkout_token' => ['required', 'string'],
        ];
    }
}
