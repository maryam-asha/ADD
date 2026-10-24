<?php

namespace Add\Inventory;

use Add\Inventory\Contracts\CustodianResolver;
use Add\Inventory\Contracts\LocationResolver;
use Add\Inventory\Integrations\OwnLocationResolver;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

/**
 * The host-facing entry points. The package mounts no route and schedules
 * no command by itself: the host calls these inside its own middleware
 * groups and its own console routes, so authentication and prefixes stay
 * the host's decision.
 */
final class Inventory
{
    /**
     * Inventory administration. Every route carries its own
     * `can:inventory.*`.
     */
    public static function adminRoutes(): void
    {
        Route::name('inventory.')->group(__DIR__.'/../routes/admin.php');
    }

    /**
     * Registers the scheduled command. Call from the host's
     * routes/console.php.
     */
    public static function schedule(): void
    {
        require __DIR__.'/../routes/schedule.php';
    }

    public static function locations(): LocationResolver
    {
        return app(LocationResolver::class);
    }

    public static function custodians(): CustodianResolver
    {
        return app(CustodianResolver::class);
    }

    /**
     * Whether the package keeps the locations itself (their CRUD routes
     * are mounted only then).
     */
    public static function ownsLocations(): bool
    {
        return self::locations() instanceof OwnLocationResolver;
    }

    /**
     * @return class-string
     */
    public static function userModel(): string
    {
        return config('inventory.user_model');
    }

    /**
     * Rules for a currency code: three letters, and one of the host's
     * currencies when config inventory.currencies.table names its table.
     *
     * @return list<mixed>
     */
    public static function currencyRules(): array
    {
        $table = config('inventory.currencies.table');

        return [
            'string',
            'size:3',
            ...($table ? [Rule::exists($table, config('inventory.currencies.column', 'code'))] : []),
        ];
    }

    /**
     * Who is acting: the signed-in host user, or null for the scheduler.
     */
    public static function actorId(): ?int
    {
        $id = auth()->id();

        return $id === null ? null : (int) $id;
    }
}
