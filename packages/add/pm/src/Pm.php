<?php

namespace Add\Pm;

use Illuminate\Support\Facades\Route;

/**
 * The host-facing entry points. The package mounts no route by itself: the
 * host calls these inside its own middleware group, so authentication and
 * prefixes stay the host's decision.
 */
final class Pm
{
    /**
     * Project management. The host decides who may reach the group; each
     * route then judges membership or its own `can:pm.*` ability.
     */
    public static function adminRoutes(): void
    {
        Route::name('pm.')->group(__DIR__.'/../routes/admin.php');
    }
}
