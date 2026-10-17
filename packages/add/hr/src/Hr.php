<?php

namespace Add\Hr;

use Illuminate\Support\Facades\Route;

/**
 * The host-facing entry points. The package mounts no route and schedules
 * no command by itself: the host calls these inside its own middleware
 * groups and its own console routes, so authentication, surfaces and
 * prefixes stay the host's decision.
 */
final class Hr
{
    /**
     * HR administration. Every route carries its own `can:hr.*`.
     */
    public static function adminRoutes(): void
    {
        Route::name('hr.')->group(fn () => self::loadModuleRoutes('admin'));
    }

    /**
     * The signed-in employee's own data. Mount behind `hr.employee`; the
     * routes check no ability.
     */
    public static function selfServiceRoutes(): void
    {
        Route::name('hr.me.')->group(fn () => self::loadModuleRoutes('self'));
    }

    /**
     * Routes whose only credential is a temporary signature (document
     * files). Mount without authentication.
     */
    public static function signedRoutes(): void
    {
        Route::name('hr.')->group(fn () => self::loadModuleRoutes('signed'));
    }

    /**
     * Routes anyone may call (the careers page). Mount without
     * authentication, rate-limited.
     */
    public static function publicRoutes(): void
    {
        Route::name('hr.')->group(fn () => self::loadModuleRoutes('public'));
    }

    /**
     * Registers the enabled modules' scheduled commands. Call from the
     * host's routes/console.php.
     */
    public static function schedule(): void
    {
        foreach (self::enabledModules() as $module) {
            $file = __DIR__."/../routes/{$module}/schedule.php";

            if (is_file($file)) {
                require $file;
            }
        }
    }

    /**
     * @return class-string
     */
    public static function userModel(): string
    {
        return config('hr.user_model');
    }

    public static function usersTable(): string
    {
        $model = self::userModel();

        return (new $model)->getTable();
    }

    private static function loadModuleRoutes(string $surface): void
    {
        foreach (self::enabledModules() as $module) {
            $file = __DIR__."/../routes/{$module}/{$surface}.php";

            if (is_file($file)) {
                require $file;
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function enabledModules(): array
    {
        return array_keys(array_filter((array) config('hr.modules', [])));
    }
}
