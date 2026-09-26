<?php

namespace App\Providers;

use App\Services\Otp\Drivers\HalaSenderOtpProvider;
use App\Services\Otp\Drivers\MockOtpProvider;
use App\Services\Otp\OtpProvider;
use Illuminate\Support\ServiceProvider;

class OtpServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(OtpProvider::class, function () {
            return match (config('services.otp.driver')) {
                'hala_sender' => new HalaSenderOtpProvider,
                default => new MockOtpProvider,
            };
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
