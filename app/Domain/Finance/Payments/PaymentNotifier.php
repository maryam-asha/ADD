<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Finance\Models\Payment;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * What a member is told about a payment they did not just make in the app —
 * a card the gateway accepted later, money taken at the desk, a cash hold
 * that lapsed. A wallet payment the member made themselves gets no message:
 * the app already showed them the result.
 *
 * Sent after commit, so a payment that rolls back tells nobody.
 */
class PaymentNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function received(Payment $payment): void
    {
        $this->send($payment, 'payment.received', ['points' => (int) ($payment->points_to_credit ?: $payment->amount_points)]);
    }

    public function expired(Payment $payment): void
    {
        $this->send($payment, 'payment.expired', ['points' => (int) $payment->amount_points]);
    }

    /**
     * @param  array<string, mixed>  $placeholders
     */
    private function send(Payment $payment, string $template, array $placeholders): void
    {
        DB::afterCommit(function () use ($payment, $template, $placeholders) {
            $member = $payment->user()->first();

            if ($member === null) {
                return;
            }

            $locale = $member->preferred_language ?? config('app.locale');

            $this->notifications->sendToUser($member, $template, $placeholders + [
                'purpose' => __('notifications.payment.purposes.'.$payment->purpose->value, [], $locale),
            ], [
                'payment_id' => $payment->id,
                'purpose' => $payment->purpose->value,
                'payable_type' => $payment->payable_type,
                'payable_id' => $payment->payable_id,
            ]);
        });
    }
}
