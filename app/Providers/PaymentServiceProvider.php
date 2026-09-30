<?php

namespace App\Providers;

use App\Domain\Finance\Payments\PaymentMethodRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the payment-method registry. Which methods exist, and which class
 * drives each, is config/payments.php — the same config-driven shape as
 * OtpServiceProvider, except the registry reads that config on every call
 * rather than once at resolution, so it is safe to share as a singleton.
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentMethodRegistry::class);
    }
}
