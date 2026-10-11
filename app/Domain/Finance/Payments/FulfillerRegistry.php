<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Contracts\PaymentFulfiller;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Which fulfiller handles each payment purpose.
 *
 * The map is given by PaymentServiceProvider, the one place a purpose is
 * wired to its class. Fulfillers are built through the container on every
 * call, like payment-method drivers, so nothing here holds state.
 */
class FulfillerRegistry
{
    /**
     * @param  array<string, class-string<PaymentFulfiller>>  $fulfillers  keyed by PaymentPurpose value
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $fulfillers,
    ) {}

    /**
     * @throws LogicException when no fulfiller is registered for the purpose
     */
    public function for(PaymentPurpose $purpose): PaymentFulfiller
    {
        $class = $this->fulfillers[$purpose->value] ?? null;

        if ($class === null) {
            throw new LogicException("No fulfiller is registered for payment purpose [{$purpose->value}].");
        }

        $fulfiller = $this->container->make($class);

        if (! $fulfiller instanceof PaymentFulfiller || $fulfiller->purpose() !== $purpose) {
            throw new LogicException("[{$class}] is not the fulfiller for payment purpose [{$purpose->value}].");
        }

        return $fulfiller;
    }
}
