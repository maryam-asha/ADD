<?php

namespace App\Http\Routing;

use App\Domain\Forms\Contracts\HasForms;
use App\Domain\Forms\Enums\FormPurpose;
use App\Http\Controllers\Api\V1\Admin\AdminFormFieldController;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use LogicException;

/**
 * Registers the admin field editor for one form owner:
 *
 *   GET|POST     {uri}
 *   PATCH|DELETE {uri}/{field}
 *
 * all served by AdminFormFieldController. Which owner and which purpose a
 * route edits travels on the route action (strings only, so `route:cache`
 * keeps working), not in a subclass per owner: the model, the requests and
 * the resource are identical for every owner, and whatever does differ
 * lives on the owner's HasForms hooks.
 *
 * Every admin route names its permission
 * (docs/decisions/rbac-permission-pilot.md, 2026-10-27), so the caller
 * passes two: the one that reads the editor (GET) and the one that changes
 * it (POST, PATCH, DELETE) — normally the owner's `view` and `update`.
 */
final class FormFieldRoutes
{
    /** Route action key holding ['owner' => alias, 'param' => name, 'purpose' => value]. */
    public const ACTION_KEY = 'form';

    /**
     * @param  class-string<HasForms>  $ownerClass
     * @param  string  $readPermission  e.g. `events.view`
     * @param  string  $writePermission  e.g. `events.update`
     */
    public static function register(string $uri, string $ownerClass, FormPurpose $purpose, string $readPermission, string $writePermission): void
    {
        if (! is_subclass_of($ownerClass, HasForms::class)) {
            throw new LogicException("{$ownerClass} does not implement ".HasForms::class.'.');
        }

        $alias = Relation::getMorphAlias($ownerClass);

        if ($alias === $ownerClass) {
            throw new LogicException("{$ownerClass} has no morph alias; add it to MorphMapServiceProvider.");
        }

        if (! preg_match_all('/\{(\w+)\}/', $uri, $matches)) {
            throw new LogicException("\"{$uri}\" has no route parameter to find the {$alias} by.");
        }

        $form = ['owner' => $alias, 'param' => end($matches[1]), 'purpose' => $purpose->value];
        $action = fn (string $method) => [
            'uses' => AdminFormFieldController::class.'@'.$method,
            self::ACTION_KEY => $form,
        ];

        $read = "permission:{$readPermission}";
        $write = "permission:{$writePermission}";

        Route::get($uri, $action('index'))->middleware($read);
        Route::post($uri, $action('store'))->middleware($write);
        Route::patch("{$uri}/{field}", $action('update'))->whereNumber('field')->middleware($write);
        Route::delete("{$uri}/{field}", $action('destroy'))->whereNumber('field')->middleware($write);
    }
}
