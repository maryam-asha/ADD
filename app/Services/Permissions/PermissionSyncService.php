<?php

namespace App\Services\Permissions;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The single source of truth for the admin-dashboard permission set — both
 * `permissions:sync` (SyncPermissionsCommand) and PermissionSeeder call
 * `sync()` here rather than duplicating this logic.
 *
 * Routes are the only source of names (docs/decisions/rbac-permission-pilot.md,
 * 2026-10-27): every `permission:` middleware on a route under
 * `api/v1/admin/` (split on `|`, Spatie's any-of), plus the names a tagged
 * `permissions.sources` publishes (the HR and Inventory packages, whose
 * routes live outside `admin/` and check `can:` abilities). There is no way
 * to register a permission that guards nothing.
 */
class PermissionSyncService
{
    public const ADMIN_URI_PREFIX = 'api/v1/admin/';

    private const GUARD = 'web';

    /**
     * Creates every derived name that is missing, re-attaches every permission
     * to `admin`, and reports the names nothing derives any more ("stale").
     * With `$prune`, the stale names are deleted — and with them every grant
     * of them, to any role or user — instead of only being reported.
     *
     * @return array{total: int, created: list<string>, stale: list<string>, pruned: bool}
     */
    public function sync(bool $prune = false): array
    {
        $derivedNames = $this->deriveNames();

        $existing = Permission::query()->where('guard_name', self::GUARD)->pluck('name')->all();
        $created = array_values(array_diff($derivedNames, $existing));

        // One insert, not a findOrCreate per name: this runs inside a
        // migration, which every RefreshDatabase test replays.
        if ($created !== []) {
            $now = now();
            Permission::query()->insert(array_map(fn (string $name) => [
                'name' => $name,
                'guard_name' => self::GUARD,
                'created_at' => $now,
                'updated_at' => $now,
            ], $created));
        }

        $stale = Permission::query()
            ->whereNotIn('name', $derivedNames)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($prune && $stale !== []) {
            $this->delete($stale);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Fresh, unfiltered Permission::query()->where('guard_name', 'web')
        // — not just what this run created — so admin ends up with every
        // web-guard permission that exists in the table, including ones
        // from a previous run or a manual Permission::create() elsewhere.
        // This is what makes admin self-healing if it's ever edited down
        // via the role-management UI: re-running the sync restores it to
        // everything. The explicit guard_name filter makes the "only 'web'
        // exists today" assumption explicit rather than implicit — nothing
        // else in this app uses a different guard yet.
        Role::findOrCreate('admin', self::GUARD)->syncPermissions(
            Permission::query()->where('guard_name', self::GUARD)->get()
        );

        return [
            'total' => Permission::count(),
            'created' => $created,
            'stale' => $stale,
            'pruned' => $prune && $stale !== [],
        ];
    }

    /**
     * Every permission name the routes and the tagged sources produce, sorted.
     *
     * @return list<string>
     */
    public function deriveNames(): array
    {
        $names = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), self::ADMIN_URI_PREFIX)) {
                continue;
            }

            foreach (self::permissionsOf(self::effectiveMiddleware($route)) as $name) {
                $names[$name] = true;
            }
        }

        /** @var PermissionSource $source */
        foreach (app()->tagged('permissions.sources') as $source) {
            foreach ($source->names() as $name) {
                $names[$name] = true;
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * A route's middleware, less whatever a withoutMiddleware() strips from it.
     *
     * @return list<mixed>
     */
    public static function effectiveMiddleware(RoutingRoute $route): array
    {
        $excluded = $route->excludedMiddleware();

        return array_values(array_filter(
            $route->gatherMiddleware(),
            fn ($middleware) => ! (is_string($middleware) && in_array($middleware, $excluded, true)),
        ));
    }

    /**
     * The permission names a route's middleware list requires, any of:
     * `permission:a|b` → [a, b]. Spatie's optional `,guard` suffix is dropped.
     *
     * @param  array<int, mixed>  $middleware
     * @return list<string>
     */
    public static function permissionsOf(array $middleware): array
    {
        $names = [];

        foreach ($middleware as $entry) {
            if (! is_string($entry) || ! str_starts_with($entry, 'permission:')) {
                continue;
            }

            foreach (explode('|', Str::before(Str::after($entry, 'permission:'), ',')) as $name) {
                if (($name = trim($name)) !== '') {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * @param  list<string>  $names
     */
    private function delete(array $names): void
    {
        $ids = Permission::query()->whereIn('name', $names)->pluck('id');

        DB::transaction(function () use ($ids) {
            DB::table(config('permission.table_names.role_has_permissions'))
                ->whereIn(config('permission.column_names.permission_pivot_key') ?? 'permission_id', $ids)
                ->delete();
            DB::table(config('permission.table_names.model_has_permissions'))
                ->whereIn(config('permission.column_names.permission_pivot_key') ?? 'permission_id', $ids)
                ->delete();
            Permission::query()->whereKey($ids)->delete();
        });
    }
}
