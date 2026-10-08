<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\Provider;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Links a dashboard account to the provider it works for. The account is
 * created first through UserController with the `provider` role; an account
 * works for one provider (`provider_user.user_id` is unique).
 */
class ProviderUserController extends Controller
{
    use LogsSensitiveActions;

    public function store(Provider $provider, User $user): JsonResponse
    {
        if (! $user->hasRole('provider')) {
            return response()->json(['message' => __('api.admin.provider_user_not_provider_role')], 422);
        }

        if ($user->providers()->exists()) {
            return response()->json(['message' => __('api.admin.provider_user_taken')], 422);
        }

        $provider->users()->attach($user->id);

        $this->logSensitiveAction('provider_user_linked', $provider, ['user_id' => $user->id]);

        return response()->json(['message' => __('api.admin.provider_user_linked')]);
    }

    public function destroy(Provider $provider, User $user): JsonResponse
    {
        abort_unless($provider->users()->where('users.id', $user->id)->exists(), 404);

        $provider->users()->detach($user->id);

        $this->logSensitiveAction('provider_user_unlinked', $provider, ['user_id' => $user->id]);

        return response()->json(['message' => __('api.admin.provider_user_unlinked')]);
    }
}
