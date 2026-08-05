<?php

namespace App\Providers;

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
                // a 'whatsapp' driver lands here once real Business API
                // credentials exist — calling code never changes.
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
