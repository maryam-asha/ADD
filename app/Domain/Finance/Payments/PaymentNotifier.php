<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Finance\Models\Payment;
use App\Services\Notification\NotificationService;
use App\Services\Notification\NotificationText;
use Illuminate\Support\Facades\DB;

/**
 * What a member is told about a payment they did not just make in the app —
 * a card the gateway accepted or failed later, money taken at the desk, a
 * cash hold that lapsed, a payment an admin reversed. A wallet payment the
 * member made themselves gets no message, nor a card payment they cancelled
 * on the gateway's page: the app already showed them the result.
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

    /** The card was declined, or the gateway never finished it. */
    public function failed(Payment $payment): void
    {
        $this->send($payment, 'payment.failed', []);
    }

    public function reversed(Payment $payment): void
    {
        $this->send($payment, 'payment.reversed', ['points' => (int) ($payment->points_to_credit ?: $payment->amount_points)]);
    }

    /**
     * Staff, not the member: a card payment the gateway accepted that does
     * not match our record, held until someone who may reverse payments
     * looks at it.
     */
    public function needsReview(Payment $payment): void
    {
        $this->notifications->sendToHolders('payments.reverse', 'payment.needs_review', [
            'id' => (string) $payment->reference,
            'points' => (int) ($payment->points_to_credit ?: $payment->amount_points),
        ], ['target_type' => 'payment', 'target_id' => (string) $payment->id]);
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

            // The purpose in every language: each reader sees their own.
            $this->notifications->sendToUser($member, $template, $placeholders + [
                'purpose' => NotificationText::translations('notifications.payment.purposes.'.$payment->purpose->value),
            ], [
                'payment_id' => $payment->id,
                'purpose' => $payment->purpose->value,
                'payable_type' => $payment->payable_type,
                'payable_id' => $payment->payable_id,
            ]);
        });
    }
}
