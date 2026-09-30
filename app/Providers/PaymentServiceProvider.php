<?php

namespace App\Providers;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Contracts\PaymentFulfiller;
use App\Domain\Finance\Payments\FulfillerRegistry;
use App\Domain\Finance\Payments\Fulfillers\BookingExtensionFulfiller;
use App\Domain\Finance\Payments\Fulfillers\BookingFulfiller;
use App\Domain\Finance\Payments\Fulfillers\PlanPurchaseFulfiller;
use App\Domain\Finance\Payments\Fulfillers\WalkinSessionFulfiller;
use App\Domain\Finance\Payments\Fulfillers\WalletTopUpFulfiller;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Payments\PaymentService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the payment layer.
 *
 * Which methods exist, and which class drives each, is config/payments.php
 * — the same config-driven shape as OtpServiceProvider, except the registry
 * reads that config on every call rather than once at resolution, so it is
 * safe to share as a singleton.
 *
 * Which fulfiller handles each purpose is the map below: a purpose that can
 * be paid for gets its line here.
 */
class PaymentServiceProvider extends ServiceProvider
{
    /**
     * @var array<string, class-string<PaymentFulfiller>>
     */
    private const FULFILLERS = [
        PaymentPurpose::WalletTopUp->value => WalletTopUpFulfiller::class,
        PaymentPurpose::PlanPurchase->value => PlanPurchaseFulfiller::class,
        PaymentPurpose::Booking->value => BookingFulfiller::class,
        PaymentPurpose::CoworkingSession->value => WalkinSessionFulfiller::class,
        PaymentPurpose::BookingExtension->value => BookingExtensionFulfiller::class,
    ];

    public function register(): void
    {
        $this->app->singleton(PaymentMethodRegistry::class);

        $this->app->singleton(
            FulfillerRegistry::class,
            fn (Application $app) => new FulfillerRegistry($app, self::FULFILLERS),
        );

        // Not a singleton: it holds no state of its own, and building it per
        // use keeps its collaborators whatever the container resolves now.
        $this->app->bind(PaymentService::class);
    }
}
