<?php

namespace App\Domain\Finance\Payments\Methods;

use App\Domain\Finance\DataTransferObjects\CreatedPayment;
use App\Domain\Finance\DataTransferObjects\PaymentStatusResult;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Contracts\PaymentGateway;
use App\Domain\Finance\Payments\PaymentStart;
use App\Domain\Finance\Services\PaymentAmountCalculator;
use App\Domain\Finance\Services\PaymeraClient;
use App\Domain\Identity\Models\User;

/**
 * Card payment through Paymera eGate, behind the gateway interface.
 *
 * Wraps PaymeraClient and changes nothing about how it is used: the HTTP
 * calls, the retry rules (create-payment never retries, status reads do)
 * and the key handling all stay in the client.
 *
 * `start()` runs AFTER the payment row has been written, on purpose. If the
 * create call times out after Paymera committed, the local record still
 * exists for the reconciler; it simply has no `payment_id` yet, and is
 * failed here explicitly if the call does not come back.
 */
class PaymeraGateway implements PaymentGateway
{
    /**
     * The value stored in `payments.method`, and this driver's key in
     * config/payments.php.
     */
    public const KEY = 'paymera';

    private const TRIGGER_ROUTE = 'paymera.trigger';

    public function __construct(
        private readonly PaymeraClient $client,
        private readonly PaymentAmountCalculator $amounts,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('api.payment.methods.'.self::KEY);
    }

    /**
     * Card money buys points, which then pay for whatever the payment is
     * for. Everything a member pays for from the app; not an extension,
     * which is only ever settled from the wallet as it happens.
     */
    public function supports(PaymentPurpose $purpose): bool
    {
        return $purpose !== PaymentPurpose::BookingExtension;
    }

    public function start(Payment $payment, array $context = []): PaymentStart
    {
        if ($payment->callback_token === null) {
            $this->prepare($payment, $context['lang'] ?? null);
        }

        try {
            $created = $this->createPayment($payment, $payment->user);
        } catch (\Throwable $e) {
            // The local row must not linger as Pending: the reconciler would
            // keep polling a payment id that does not exist. It is marked
            // failed here, and never retried automatically.
            $payment->forceFill([
                'status' => PaymentStatus::Failed,
                'review_reason' => 'create-payment failed: '.$e->getMessage(),
            ])->save();

            throw $e;
        }

        $payment->forceFill([
            'payment_id' => $created->paymentId,
        ])->save();

        return PaymentStart::redirect($created->url);
    }

    /**
     * A payment opened through PaymentService (a booking, a session) arrives
     * with only what is due in points. The card side is derived here, the
     * way StartPayment derives it for a plan: the points are the price, the
     * charge is computed from them, and both are frozen on the row before
     * the gateway is called.
     */
    private function prepare(Payment $payment, ?string $lang): void
    {
        [$points, $chargeMinor] = $this->amounts->forPoints((int) $payment->amount_points);

        $payment->forceFill([
            'callback_token' => Payment::newCallbackToken(),
            'amount_minor' => $chargeMinor,
            'currency' => (string) config('paymera.currency'),
            'currency_exponent' => (int) config('paymera.currency_exponent'),
            'points_to_credit' => $points,
            'terminal_id' => (string) config('paymera.terminal_id'),
            'lang' => $lang ?? (string) config('paymera.default_lang'),
        ])->save();
    }

    public function createPayment(Payment $payment, User $payer): CreatedPayment
    {
        return $this->client->createPayment(
            amountMinor: $payment->amount_minor,
            callbackUrl: route($this->callbackRouteName(), ['token' => $payment->callback_token]),
            triggerUrl: route(self::TRIGGER_ROUTE, ['token' => $payment->callback_token]),
            lang: $payment->lang,
            extra: $this->savedCardOptions($payer),
        );
    }

    public function fetchStatus(Payment $payment): PaymentStatusResult
    {
        return $this->client->getPaymentStatus($payment->payment_id);
    }

    public function cancel(Payment $payment): void
    {
        $this->client->cancelPayment($payment->payment_id, $payment->lang);
    }

    public function callbackRouteName(): string
    {
        return 'paymera.callback';
    }

    public function ledgerMethod(): PaymentMethod
    {
        return PaymentMethod::Paymera;
    }

    /**
     * `appUser` is mandatory whenever savedCards is on — it is the identity
     * Paymera files the stored card under. Sending our own user id keeps
     * one member's saved cards from ever surfacing for another.
     *
     * @return array<string, mixed>
     */
    private function savedCardOptions(User $payer): array
    {
        if (! config('paymera.saved_cards_enabled')) {
            return [];
        }

        return [
            'savedCards' => 1,
            'appUser' => (string) $payer->id,
        ];
    }
}
