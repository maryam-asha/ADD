<?php

namespace App\Domain\Finance\DataTransferObjects;

use App\Domain\Finance\Exceptions\PaymeraRequestFailedException;

/**
 * What create-payment gives back: the hosted page to send the member to,
 * and the id every later status check is keyed on.
 */
final readonly class CreatedPayment
{
    public function __construct(
        public string $paymentId,
        public string $url,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the response's `Data` object
     */
    public static function fromResponse(array $data): self
    {
        $paymentId = $data['paymentId'] ?? null;
        $url = $data['url'] ?? null;

        if (! is_string($paymentId) || $paymentId === '' || ! is_string($url) || $url === '') {
            throw PaymeraRequestFailedException::malformedResponse(
                'create-payment response is missing paymentId or url'
            );
        }

        return new self($paymentId, $url);
    }
}
