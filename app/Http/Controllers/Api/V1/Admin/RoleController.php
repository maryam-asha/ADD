<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Support\ProtectedRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Deliberately not extending AdminResourceController: roles have no `order`
 * column, and destroy() needs a protected-role guard (and an in-use guard)
 * the generic destroy doesn't have — same reasoning as UserController.
 */
class RoleController extends Controller
{
    use LogsSensitiveActions;

    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(Role::with('permissions')->get());
    }

    public function store(StoreRoleRequest $request): RoleResource
    {
        $role = Role::create(['name' => $request->validated('name'), 'guard_name' => 'web']);

        if ($permissions = $request->validated('permissions')) {
            $role->syncPermissions($permissions);
        }

        $this->logSensitiveAction('role_created', $role, ['permissions' => $permissions ?? []]);

        return new RoleResource($role->load('permissions'));
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $newName = $request->validated('name');

        if ($newName !== null && $newName !== $role->name && in_array($role->name, ProtectedRoles::NAMES, true)) {
            return response()->json(['message' => __('api.role.protected_rename')], 422);
        }

        $before = ['name' => $role->name, 'permissions' => $role->permissions()->pluck('name')];

        if ($newName !== null) {
            $role->update(['name' => $newName]);
        }

        if ($request->has('permissions')) {
            $role->syncPermissions($request->validated('permissions') ?? []);
        }

        $this->logSensitiveAction('role_updated', $role, [
            'before' => $before,
            'after' => ['name' => $role->name, 'permissions' => $role->permissions()->pluck('name')],
        ]);

        return response()->json(['message' => __('api.admin.role_updated')]);
    }

    public function destroy(Role $role): JsonResponse
    {
        if (in_array($role->name, ProtectedRoles::NAMES, true)) {
            return response()->json(['message' => __('api.role.protected_delete')], 422);
        }

        if ($role->users()->exists()) {
            return response()->json(['message' => __('api.role.role_in_use')], 422);
        }

        $this->logSensitiveAction('role_deleted', $role, ['name' => $role->name]);

        $role->delete();

        return response()->json(['message' => __('api.admin.role_deleted')]);
    }

    public function permissions(): JsonResponse
    {
        $grouped = Permission::query()->orderBy('name')->get()
            ->map(fn (Permission $p) => [
                'name' => $p->name,
                'module' => Str::before($p->name, '.'),
                'action' => Str::after($p->name, '.'),
            ])
            ->groupBy('module')
            ->map(fn ($items, $module) => [
                'module' => $module,
                // $items holds plain arrays (from the ->map() above), not
                // objects — Collection's ->map->only(...) higher-order proxy
                // would call ->only() on each array item, which arrays don't
                // have; Arr::only() per item is the array-safe equivalent.
                'actions' => $items->map(fn (array $item) => Arr::only($item, ['name', 'action']))->values(),
            ])
            ->values();

        return response()->json(['data' => $grouped]);
    }
}
