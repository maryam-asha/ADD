<?php

namespace App\Domain\Identity\Enums;

/**
 * The client a push token was registered from. The member app receives
 * member notices; a dashboard browser receives the staff and provider
 * notices of the account signed in to it.
 */
enum DeviceSurface: string
{
    case App = 'app';
    case Dashboard = 'dashboard';
}
