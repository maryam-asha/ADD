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
     * A deliberately partial, hand-picked list — NOT every controller that
     * fails to extend AdminResourceController. It covers exactly these four
     * (see each controller's own class docblock for why — no `order`
     * column, destructive-delete/status semantics that don't fit the
     * generic CRUD shape), because this pilot only converted those four to
     * permission-derived coverage. At least a dozen more admin controllers
     * (`CompanyController`, `CompanyMemberController`, `CurrencyController`,
     * `ExchangeRateController`, `ExchangeRateSuggestionController`,
     * `PrivacyPolicyController`, and all six `Reception/*Controller`
     * classes) also don't extend AdminResourceController and get NO
     * permission coverage today — an acknowledged gap, not a silent one:
     * see docs/decisions/rbac-permission-pilot.md's "Explicitly not done
     * here", and `uncoveredControllers()` below, which surfaces the
     * non-Reception half of that gap in `permissions:sync`'s own output so
     * it stays visible rather than assumed-exhaustive.
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
     * @return array{total: int, created: string[], stale: string[], uncovered: string[]}
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

        // Fresh, unfiltered Permission::query()->where('guard_name', 'web')
        // — not just what this run created — so admin ends up with every
        // web-guard permission that exists in the table, including ones
        // from a previous run or a manual Permission::create() elsewhere.
        // This is what makes admin self-healing if it's ever edited down
        // via the role-management UI: re-running the sync restores it to
        // everything. The explicit guard_name filter makes the "only 'web'
        // exists today" assumption explicit rather than implicit — nothing
        // else in this app uses a different guard yet.
        Role::findOrCreate('admin', 'web')->syncPermissions(
            Permission::query()->where('guard_name', 'web')->get()
        );

        $stale = Permission::query()
            ->whereNotIn('name', $derivedNames)
            ->pluck('name')
            ->all();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [
            'total' => Permission::count(),
            'created' => $created,
            'stale' => $stale,
            'uncovered' => $this->uncoveredControllers(),
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
     * Api/V1/Admin/Reception/*Controller.php, which is out of scope — see
     * uncoveredControllers() below, which uses the same glob for the same
     * reason.
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

    /**
     * Admin controllers that get no permission coverage at all today:
     * neither reflected by discoverControllers() (they don't extend
     * AdminResourceController) nor listed in MANUAL_REGISTRATIONS. This
     * makes the gap `permissions:sync` reports visible instead of a silent
     * assumption that the manual list is exhaustive.
     *
     * Same flat, non-recursive glob as discoverControllers(), so
     * Api/V1/Admin/Reception/*Controller.php is excluded here too, on
     * purpose, not by omission: its actions (checkIn/checkOut/approve/
     * reject/settlePayment/...) don't fit the index/show/store/update/
     * destroy vocabulary this service derives module.action permissions
     * from at all, so flagging it here wouldn't point at an actionable fix
     * the same way the other controllers in this list do — it would need
     * its own custom permission-naming scheme first. See
     * docs/decisions/rbac-permission-pilot.md's "Explicitly not done here"
     * for the full reasoning and the six Reception controller names.
     *
     * @return list<class-string>
     */
    private function uncoveredControllers(): array
    {
        $covered = array_merge($this->discoverControllers(), array_keys(self::MANUAL_REGISTRATIONS));

        $uncovered = [];

        foreach (glob(app_path('Http/Controllers/Api/V1/Admin/*Controller.php')) ?: [] as $file) {
            $class = 'App\\Http\\Controllers\\Api\\V1\\Admin\\'.basename($file, '.php');

            if ($class === AdminResourceController::class || ! class_exists($class)) {
                continue;
            }

            if (! in_array($class, $covered, true)) {
                $uncovered[] = $class;
            }
        }

        sort($uncovered);

        return $uncovered;
    }
}
