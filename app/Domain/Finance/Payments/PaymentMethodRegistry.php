<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Contracts\PaymentGateway;
use App\Domain\Finance\Payments\Contracts\PaymentMethodDriver;
use App\Domain\Finance\Payments\Exceptions\UnknownPaymentMethodException;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * The single place that knows which payment methods exist.
 *
 * Built from `config('payments.methods')`, and deliberately stateless: config
 * is read on every call and drivers are built through the container each
 * time. The registry is a container singleton, but nothing is cached in it,
 * so a method toggled in config (or a driver swapped in the container) after
 * the registry was first resolved is still seen.
 *
 * `enabled` gates NEW payments. `gatewayFor()` ignores it on purpose: a
 * payment already opened at a gateway has to stay settleable, reconcilable
 * and reversible even after an operator switches that method off.
 */
class PaymentMethodRegistry
{
    public function __construct(private readonly Container $container) {}

    /**
     * Every enabled method, in config order.
     *
     * @return array<string, PaymentMethodDriver>
     */
    public function enabled(): array
    {
        $methods = [];

        foreach ($this->definitions() as $key => $definition) {
            if ($this->isEnabled($definition)) {
                $methods[$key] = $this->make($key, $definition);
            }
        }

        return $methods;
    }

    /**
     * @throws UnknownPaymentMethodException when the key is not registered or is disabled
     */
    public function get(string $key): PaymentMethodDriver
    {
        $definition = $this->definitions()[$key] ?? null;

        if ($definition === null) {
            throw UnknownPaymentMethodException::unknown($key);
        }

        if (! $this->isEnabled($definition)) {
            throw UnknownPaymentMethodException::disabled($key);
        }

        return $this->make($key, $definition);
    }

    /**
     * The gateway a payment was opened with.
     *
     * Resolved by the payment's own `method`, and whether or not that method
     * is still enabled (see the class note).
     *
     * @throws UnknownPaymentMethodException when the method is not registered, or is not a gateway
     */
    public function gatewayFor(Payment $payment): PaymentGateway
    {
        $key = (string) $payment->method;
        $definition = $this->definitions()[$key] ?? null;

        if ($definition === null) {
            throw UnknownPaymentMethodException::unknown($key);
        }

        $driver = $this->make($key, $definition);

        if (! $driver instanceof PaymentGateway) {
            throw UnknownPaymentMethodException::notAGateway($key);
        }

        return $driver;
    }

    /**
     * The keys of every registered method that is a gateway, enabled or not
     * — the set of methods whose payments have an outcome to go and ask for.
     *
     * @return list<string>
     */
    public function gatewayKeys(): array
    {
        $keys = [];

        foreach ($this->definitions() as $key => $definition) {
            if (is_a($definition['driver'] ?? null, PaymentGateway::class, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The enabled methods that can pay for the purpose, in config order.
     *
     * @return array<string, PaymentMethodDriver>
     */
    public function availableFor(PaymentPurpose $purpose): array
    {
        return array_filter(
            $this->enabled(),
            fn (PaymentMethodDriver $driver) => $driver->supports($purpose)
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function definitions(): array
    {
        return (array) config('payments.methods', []);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function isEnabled(array $definition): bool
    {
        return (bool) ($definition['enabled'] ?? true);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function make(string $key, array $definition): PaymentMethodDriver
    {
        $class = $definition['driver'] ?? null;

        if (! is_string($class) || ! is_a($class, PaymentMethodDriver::class, true)) {
            throw new LogicException("Payment method [{$key}] must name a driver implementing PaymentMethodDriver.");
        }

        return $this->container->make($class);
    }
}
