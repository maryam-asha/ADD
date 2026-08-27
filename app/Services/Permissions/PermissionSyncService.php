<?php

namespace App\Services\Permissions;

use App\Http\Controllers\Api\V1\Admin\AdminResourceController;
use App\Http\Controllers\Api\V1\Admin\ErrorLogController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The single source of truth for deriving admin-dashboard permissions from
 * registered routes/controllers — both `permissions:sync` (SyncPermissionsCommand)
 * and PermissionSeeder call `sync()` here rather than duplicating this logic.
 */
class PermissionSyncService
{
    /**
     * Controllers that deliberately don't extend AdminResourceController (see
     * each controller's own class docblock for why — no `order` column,
     * destructive-delete/status semantics that don't fit the generic CRUD
     * shape), so reflection-based discovery never finds them. Their
     * permission set is hardcoded here instead of derived from routes.
     *
     * @var array<class-string, array<string, list<string>>>
     */
    private const MANUAL_REGISTRATIONS = [
        UserController::class => [
            'users' => ['view', 'create', 'update', 'update_status', 'assign_role'],
        ],
        ErrorLogController::class => [
            'error-logs' => ['view', 'delete'],
        ],
        RoleController::class => [
            'roles' => ['view', 'create', 'update', 'delete'],
        ],
        SettingController::class => [
            'settings' => ['view', 'update'],
        ],
    ];

    /**
     * Fixed method-name -> action-word mapping. Any other method name (a
     * custom action like `updateStatus`) is intentionally not mapped —
     * skipped silently rather than guessed at.
     *
     * @var array<string, string>
     */
    private const METHOD_ACTIONS = [
        'index' => 'view',
        'show' => 'view',
        'store' => 'create',
        'update' => 'update',
        'destroy' => 'delete',
    ];

    /**
     * @return array{total: int, created: string[], stale: string[]}
     */
    public function sync(): array
    {
        $derivedNames = $this->deriveNames();

        $created = [];
        foreach ($derivedNames as $name) {
            $permission = Permission::findOrCreate($name, 'web');

            if ($permission->wasRecentlyCreated) {
                $created[] = $name;
            }
        }

        // Fresh, unfiltered Permission::all() — not just what this run
        // created — so admin ends up with every permission that exists in
        // the table, including ones from a previous run or a manual
        // Permission::create() elsewhere. This is what makes admin
        // self-healing if it's ever edited down via the role-management UI:
        // re-running the sync restores it to everything.
        Role::findOrCreate('admin', 'web')->syncPermissions(Permission::all());

        $stale = Permission::query()
            ->whereNotIn('name', $derivedNames)
            ->pluck('name')
            ->all();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [
            'total' => Permission::count(),
            'created' => $created,
            'stale' => $stale,
        ];
    }

    /**
     * @return list<string>
     */
    private function deriveNames(): array
    {
        $names = [];

        foreach ($this->discoverControllers() as $controller) {
            foreach ($this->derivePermissionsForController($controller) as $name) {
                $names[$name] = true;
            }
        }

        foreach (self::MANUAL_REGISTRATIONS as $modules) {
            foreach ($modules as $module => $actions) {
                foreach ($actions as $action) {
                    $names["{$module}.{$action}"] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * Flat (non-recursive) glob, deliberately: this does NOT reach
     * Api/V1/Admin/Reception/*Controller.php, which is out of scope.
     *
     * @return list<class-string>
     */
    private function discoverControllers(): array
    {
        $controllers = [];

        foreach (glob(app_path('Http/Controllers/Api/V1/Admin/*Controller.php')) ?: [] as $file) {
            $class = 'App\\Http\\Controllers\\Api\\V1\\Admin\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isSubclassOf(AdminResourceController::class) && ! $reflection->isAbstract()) {
                $controllers[] = $class;
            }
        }

        return $controllers;
    }

    /**
     * @param  class-string  $controller
     * @return list<string>
     */
    private function derivePermissionsForController(string $controller): array
    {
        $names = [];

        foreach (Route::getRoutes() as $route) {
            $actionName = $route->getActionName();

            if (! str_contains($actionName, '@')) {
                continue;
            }

            [$routeController, $method] = explode('@', $actionName, 2);

            if ($routeController !== $controller) {
                continue;
            }

            if (! isset(self::METHOD_ACTIONS[$method])) {
                continue;
            }

            $uri = $route->uri();
            $afterAdmin = Str::after($uri, 'v1/admin/');

            if ($afterAdmin === $uri) {
                continue;
            }

            $module = Str::before(Str::before($afterAdmin, '/'), '{');

            if ($module === '') {
                continue;
            }

            $names[] = "{$module}.".self::METHOD_ACTIONS[$method];
        }

        return array_values(array_unique($names));
    }
}
