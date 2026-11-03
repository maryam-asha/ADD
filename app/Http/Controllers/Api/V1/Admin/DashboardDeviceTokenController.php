<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Concerns\RegistersDashboardDeviceToken;
use App\Http\Controllers\Controller;

/**
 * A staff account's dashboard browser, for its staff notices.
 */
class DashboardDeviceTokenController extends Controller
{
    use RegistersDashboardDeviceToken;
}
