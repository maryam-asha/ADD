<?php

namespace App\Http\Resources\Finance;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A card payment as the member app and the dashboard see it.
 *
 * Deliberately omitted: `callback_token` (it is the credential the public
 * callback/trigger routes are protected by) and `raw_response` (the
 * gateway's verbatim payload, which is for dispute investigation in the
 * database, not for clients). `review_reason` is admin-only — it describes
 * an internal fault and would mean nothing useful to a member.
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->hasAnyRole(['admin', 'operations']) ?? false;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'purpose' => $this->purpose->value,

            // What the card was charged, as charged: the gateway's integer
            // minor units plus the exponent needed to render it. The client
            // formats it; nothing here pre-rounds it into a float.
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'currency_exponent' => $this->currency_exponent,

            'points_to_credit' => $this->points_to_credit,
            'plan_id' => $this->plan_id,
            'membership_id' => $this->membership_id,

            // The bank's reference — what a member quotes when disputing.
            'rrn' => $this->rrn,

            'created_at' => $this->created_at?->toISOString(),

            $this->mergeWhen($isAdmin, fn () => [
                'gateway_payment_id' => $this->payment_id,
                'user_id' => $this->user_id,
                'wallet_id' => $this->wallet_id,
                'wallet_transaction_id' => $this->wallet_transaction_id,
                'terminal_id' => $this->terminal_id,
                'review_reason' => $this->review_reason,
                'last_checked_at' => $this->last_checked_at?->toISOString(),
                'check_attempts' => $this->check_attempts,
                'gateway_created_at' => $this->gateway_created_at?->toISOString(),
                'reversed_at' => $this->reversed_at?->toISOString(),
                'reversed_by' => $this->reversed_by,
            ]),
        ];
    }
}
