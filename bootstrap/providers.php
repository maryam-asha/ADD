<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HrIntegrationServiceProvider;
use App\Providers\InventoryIntegrationServiceProvider;
use App\Providers\MorphMapServiceProvider;
use App\Providers\NotificationServiceProvider;
use App\Providers\OtpServiceProvider;
use App\Providers\PaymentServiceProvider;
use App\Providers\PmIntegrationServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HrIntegrationServiceProvider::class,
    InventoryIntegrationServiceProvider::class,
    MorphMapServiceProvider::class,
    NotificationServiceProvider::class,
    OtpServiceProvider::class,
    PaymentServiceProvider::class,
    PmIntegrationServiceProvider::class,
];
