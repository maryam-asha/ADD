<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignRoleRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;

/**
 * Deliberately not extending AdminResourceController: users don't have an
 * 'order' column, and "removing" a user means deactivating (status), not a
 * hard delete — the shape here genuinely differs from the content resources.
 */
class UserController extends Controller
{
    use LogsSensitiveActions;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::query()->with('roles')->latest();

        if ($role = $request->query('role')) {
            $query->role($role);
        }

        return UserResource::collection($query->get());
    }

    public function store(StoreUserRequest $request): UserResource
    {
        $user = User::create([
            ...$request->safe()->except(['password', 'role']),
            'password' => Hash::make($request->validated('password')),
            'preferred_language' => 'ar',
            'status' => 'active',
        ]);

        $user->assignRole($request->validated('role'));

        $this->logSensitiveAction('user_created', $user, ['role' => $request->validated('role')]);

        return new UserResource($user);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $user->update($request->validated());

        return new UserResource($user);
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): UserResource
    {
        $before = $user->status;
        $reason = $request->validated('reason');

        match ($request->validated('status')) {
            'active' => $user->activate($reason),
            'deactivated' => $user->deactivate($reason),
            'blocked' => $user->block($reason),
        };

        $this->logSensitiveAction('user_status_changed', $user, [
            'before' => $before,
            'after' => $user->status,
            'reason' => $reason,
        ]);

        return new UserResource($user);
    }

    public function assignRole(AssignRoleRequest $request, User $user): UserResource
    {
        $before = $user->getRoleNames();

        $user->syncRoles([$request->validated('role')]);

        $this->logSensitiveAction('user_role_changed', $user, [
            'before' => $before,
            'after' => $user->getRoleNames(),
        ]);

        return new UserResource($user);
    }
}
