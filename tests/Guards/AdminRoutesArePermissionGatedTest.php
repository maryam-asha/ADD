<?php

namespace Tests\Guards;

use App\Services\Permissions\PermissionSyncService;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * docs/decisions/rbac-permission-pilot.md (2026-10-27): any role reaches
 * exactly the admin API its permissions grant, and no role name is checked
 * for staff access. So every route under api/v1/admin/ names a
 * `permission:` middleware — the only exceptions are the two routes with no
 * resource of their own, which take any staff account (`staff`) — and none
 * checks a role. Written prose is a request, not a guarantee (PRD §9).
 */
class AdminRoutesArePermissionGatedTest extends TestCase
{
    /** Routes reachable by any account holding at least one permission. */
    private const STAFF_ONLY = [
        'GET api/v1/admin/me',
        'POST api/v1/admin/media/upload',
    ];

    public function test_every_admin_route_names_a_permission_except_the_staff_only_allowlist(): void
    {
        $ungated = [];

        foreach ($this->adminRoutes() as $key => $route) {
            $middleware = PermissionSyncService::effectiveMiddleware($route);

            if (in_array($key, self::STAFF_ONLY, true)) {
                $this->assertContains('staff', $middleware, "{$key} is allowlisted but carries no staff middleware.");

                continue;
            }

            if (PermissionSyncService::permissionsOf($middleware) === []) {
                $ungated[] = $key;
            }
        }

        $this->assertSame([], $ungated, 'Admin routes with no permission: middleware.');
    }

    public function test_no_admin_route_checks_a_role(): void
    {
        $roleChecks = [];

        foreach ($this->adminRoutes() as $key => $route) {
            foreach (PermissionSyncService::effectiveMiddleware($route) as $middleware) {
                if (is_string($middleware) && (str_starts_with($middleware, 'role:') || str_starts_with($middleware, 'role_or_permission:'))) {
                    $roleChecks[] = "{$key} ({$middleware})";
                }
            }
        }

        $this->assertSame([], $roleChecks, 'Admin routes must check permissions, never a role name.');
    }

    public function test_the_admin_group_still_requires_a_dashboard_session(): void
    {
        foreach ($this->adminRoutes() as $key => $route) {
            $middleware = PermissionSyncService::effectiveMiddleware($route);

            $this->assertContains('auth:sanctum', $middleware, "{$key} lost auth:sanctum.");
            $this->assertContains('abilities:dashboard', $middleware, "{$key} lost abilities:dashboard.");
        }
    }

    public function test_the_allowlist_names_real_routes(): void
    {
        $this->assertSame([], array_values(array_diff(self::STAFF_ONLY, array_keys($this->adminRoutes()))));
    }

    /**
     * @return array<string, RoutingRoute> keyed "METHOD uri"
     */
    private function adminRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), PermissionSyncService::ADMIN_URI_PREFIX)) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes["{$method} {$route->uri()}"] = $route;
            }
        }

        $this->assertNotEmpty($routes);

        return $routes;
    }
}
