<?php

namespace App\Domain\Finance\DataTransferObjects;

use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Exceptions\PaymeraRequestFailedException;

/**
 * A get-payment-status reading — the only trustworthy statement about
 * whether a payment succeeded. Neither the member arriving at callbackURL
 * nor Paymera hitting triggerURL says anything about the outcome; both are
 * merely prompts to come and ask.
 *
 * `terminalId` is normalized to a string on the way in: Paymera's own
 * documentation shows it quoted in some examples and bare in others, and a
 * loose comparison between 99990001 and "99990001" is exactly the kind of
 * thing that silently disables a security check.
 */
final readonly class PaymentStatusResult
{
    public function __construct(
        public PaymentStatus $status,
        public string $rawStatus,
        public int $amountMinor,
        public string $terminalId,
        public ?string $rrn,
        public ?string $creationTimestamp,
        public ?string $notes,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the response's `Data` object
     */
    public static function fromResponse(array $data): self
    {
        $status = $data['status'] ?? null;

        if (! is_string($status) || $status === '') {
            throw PaymeraRequestFailedException::malformedResponse(
                'get-payment-status response is missing status'
            );
        }

        // A missing amount must not read as 0 — that would compare equal to
        // nothing we ever send and so would always be caught by the amount
        // check, but as a confusing "mismatch" rather than a clear fault.
        if (! array_key_exists('amount', $data) || ! is_numeric($data['amount'])) {
            throw PaymeraRequestFailedException::malformedResponse(
                'get-payment-status response is missing a numeric amount'
            );
        }

        return new self(
            status: PaymentStatus::fromGatewayStatus($status),
            rawStatus: $status,
            amountMinor: (int) $data['amount'],
            terminalId: isset($data['terminalId']) ? (string) $data['terminalId'] : '',
            rrn: isset($data['rrn']) ? (string) $data['rrn'] : null,
            creationTimestamp: isset($data['creationTimestamp']) ? (string) $data['creationTimestamp'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            raw: $data,
        );
    }
}
