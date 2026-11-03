<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Concerns\RegistersDashboardDeviceToken;
use App\Http\Controllers\Controller;

/**
 * A provider account's portal browser, for its provider notices.
 */
class ProviderDeviceTokenController extends Controller
{
    use RegistersDashboardDeviceToken;
}
