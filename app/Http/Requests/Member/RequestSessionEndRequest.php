<?php

namespace App\Http\Requests\Member;

use App\Domain\Finance\Payments\PaymentMethodRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The member's "end session" press, with how they will pay: `wallet` spends
 * their points when reception confirms, `cash` is handed over at the desk, a
 * gateway (`paymera`) is paid in the app once the session is closed and
 * priced. Optional, so an app that does not send it keeps today's behaviour:
 * reception settles it later
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
            'payment_method' => ['nullable', 'string', Rule::in(array_keys(app(PaymentMethodRegistry::class)->enabled()))],
        ];
    }
}
