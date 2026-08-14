<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Concerns\LogsSensitiveActions;
use App\Http\Controllers\Controller;
use App\Services\Auth\TokenPairService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AccountController extends Controller
{
    use LogsSensitiveActions;

    public function __construct(
        private readonly TokenPairService $tokens,
    ) {}

    public function delete(): JsonResponse
    {
        $user = auth()->user();
        $user->delete();

        return response()->json(['message' => __('api.auth.account_deleted')]);
    }

    /**
     * Voluntary pause, self-service — the counterpart to admin-side
     * deactivate/block (PATCH admin/users/{user}/status). A member can undo
     * this themselves via the WhatsApp OTP round trip
     * (AccountReactivationController); only an admin can undo a block.
     */
    public function deactivate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = auth()->user();
        $user->deactivate($validated['reason'] ?? null);

        $this->logSensitiveAction('user_self_deactivated', $user, ['reason' => $validated['reason'] ?? null]);

        return response()->json(['message' => __('api.auth.account_deactivated')]);
    }

    public function updateProfileImage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'avatar_url' => 'required|image|max:5120',
        ]);

        $file = $request->file('avatar_url');
        $path = $file->store('users/'.auth()->id(), 'public');

        auth()->user()->personalProfile()->updateOrCreate(
            ['user_id' => auth()->id()],
            ['avatar_url' => $path]
        );

        return response()->json(['message' => __('api.profile.image_updated')]);
    }

    /**
     * The authenticated alternative to the OTP-based reset
     * (MemberPasswordController): identity is proven with the current
     * password instead of a WhatsApp code, on a session that already exists.
     * That is also why this only cuts every *other* session rather than all
     * of them — the one making this request already proved itself and has no
     * reason to be logged out of its own device.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = auth()->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['message' => __('api.auth.current_password_incorrect')], 422);
        }

        DB::transaction(function () use ($user, $validated, $request) {
            $user->forceFill(['password' => $validated['password']])->save();

            $this->tokens->revokeAllExcept($user, $request->user()->currentAccessToken());
        });

        $this->logSensitiveAction('user_password_changed', $user);

        return response()->json(['message' => __('api.auth.password_changed')]);
    }
}
