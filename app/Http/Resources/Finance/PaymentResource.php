<?php

namespace App\Http\Resources\Finance;

use App\Http\Resources\Concerns\PreloadsAppliedDiscounts;
use App\Support\Presenters\AppliedDiscountPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment as the member app and the dashboard see it, whatever the method
 * (wallet points, cash at reception, a card gateway).
 *
 * Deliberately omitted: `callback_token` (it is the credential the public
 * callback/trigger routes are protected by) and `raw_response` (the
 * gateway's verbatim payload, which is for dispute investigation in the
 * database, not for clients). `review_reason` is admin-only — it describes
 * an internal fault and would mean nothing useful to a member.
 */
class PaymentResource extends JsonResource
{
    use PreloadsAppliedDiscounts;

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
            'method' => $this->method,

            // What the card was charged, as charged: the gateway's integer
            // minor units plus the exponent needed to render it. The client
            // formats it; nothing here pre-rounds it into a float. All three
            // are null for a payment that did not go through a gateway.
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'currency_exponent' => $this->currency_exponent,

            'points_to_credit' => $this->points_to_credit,

            // What the payment is worth in points, whatever the method.
            'amount_points' => $this->amount_points,

            // The row it shows up as in the member's wallet history
            // (member/wallet/transactions/{id}): the spend, or for money
            // that only came in (a top-up), the credit. Null until settled.
            'transaction_id' => $this->debit_transaction_id ?? $this->wallet_transaction_id,

            // What it pays for, as the morph alias plus the id — enough for
            // the client to find the booking or session without a second
            // model being loaded here.
            'payable' => $this->payable_type === null
                ? null
                : ['type' => $this->payable_type, 'id' => $this->payable_id],

            'expires_at' => $this->expires_at?->toISOString(),
            'plan_id' => $this->plan_id,
            'membership_id' => $this->membership_id,

            // The bank's reference — what a member quotes when disputing.
            'rrn' => $this->rrn,

            // The gateway's own id for it (Paymera's paymentId, also in
            // `redirect_url`) — not `id`, which is ours and what Get Payment
            // Status takes. Null for a payment no gateway handled.
            'gateway_payment_id' => $this->payment_id,

            // What was taken off it — offer, coupon, plan discount — as it
            // was when the price was locked (AppliedDiscountPresenter).
            'discounts' => app(AppliedDiscountPresenter::class)->for($this->resource),

            'created_at' => $this->created_at?->toISOString(),

            $this->mergeWhen($isAdmin, fn () => [
                'method_label' => __("api.payment.methods.{$this->method}"),
                // Who paid, when the list loaded them.
                'user' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'phone' => $this->user->phone,
                ]),
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
                'refunded_at' => $this->refunded_at?->toISOString(),

                // Cash taken at the desk: in what, how much, by whom.
                'collected_via' => $this->collected_via?->value,
                'received_amount' => $this->received_amount,
                'received_currency' => $this->received_currency,
                'received_by' => $this->received_by,
                'received_by_name' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy?->name),
                'received_at' => $this->received_at?->toISOString(),
            ]),
        ];
    }
}
