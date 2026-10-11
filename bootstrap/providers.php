<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\MorphMapServiceProvider;
use App\Providers\NotificationServiceProvider;
use App\Providers\OtpServiceProvider;
use App\Providers\PaymentServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    MorphMapServiceProvider::class,
    NotificationServiceProvider::class,
    OtpServiceProvider::class,
    PaymentServiceProvider::class,
];
