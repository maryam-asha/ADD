<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ReactivateAccountRequest;
use App\Http\Requests\Auth\ReactivateAccountVerifyRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\TokenPairService;
use App\Services\Otp\Exceptions\OtpThrottledException;
use App\Services\Otp\OtpResult;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Self-service reactivation for a member who deactivated their own account —
 * the deactivated-only counterpart to admin-side unblocking. A deactivated
 * account has no valid token (deactivate() revokes every one it held) and
 * login refuses it, so this can't go through login or `me`; the WhatsApp code
 * is the only channel left to prove it's really the account holder asking.
 *
 * A blocked account is deliberately out of reach here — memberFor() only
 * resolves a `deactivated` account, so a blocked member gets the same neutral
 * non-behavior as an active account or an unknown phone. Reversing a block is
 * an admin decision (PATCH admin/users/{user}/status), not something the
 * account itself can undo.
 */
class AccountReactivationController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly TokenPairService $tokens,
    ) {}

    /**
     * Always answers the same way, whether or not the number belongs to a
     * deactivated account.
     *
     * The throttle exception is swallowed rather than surfaced for the same
     * reason as password/forgot: a 429 on the second call would mark the
     * numbers that actually received something, and a plain 200 on the rest.
     * The cooldown still does its job — the code simply isn't re-sent — and
     * route-level throttling covers outright abuse of the endpoint.
     */
    public function request(ReactivateAccountRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');

        if ($this->memberFor($phone)) {
            try {
                $this->otp->request($phone, OtpPurpose::AccountReactivation);
            } catch (OtpThrottledException) {
                // Intentionally indistinguishable from the success path.
            }
        }

        return response()->json([
            'message' => __('api.auth.account_reactivation_code_sent'),
        ]);
    }

    /**
     * Restores the account and signs it straight in — there is no separate
     * "now log in" step, since the account has no other way back to a session.
     */
    public function verify(ReactivateAccountVerifyRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');

        $user = $this->memberFor($phone);

        // Checked before the code is spent, and answered as an invalid code so
        // the endpoint stays as silent as request() about who has a
        // deactivated account here. request() never mints a code for anyone
        // this wouldn't apply to, so this should be unreachable — it is the
        // backstop, not the gate. It is also what keeps a blocked account
        // from ever reactivating even if a code somehow existed for it.
        if (! $user) {
            return response()->json(['message' => __('api.auth.code_invalid')], 422);
        }

        $result = $this->otp->verify($phone, $request->validated('code'), OtpPurpose::AccountReactivation);

        if ($result === OtpResult::PurposeMismatch) {
            return response()->json([
                'message' => __('api.auth.code_purpose_mismatch'),
            ], 422);
        }

        if ($result !== OtpResult::Verified) {
            return response()->json(['message' => __('api.auth.code_invalid')], 422);
        }

        $pair = DB::transaction(function () use ($user) {
            $user->activate('self_reactivated_via_otp');

            return $this->tokens->issue($user);
        });

        return response()->json([
            ...$pair->toArray(),
            'user' => new UserResource($user),
        ]);
    }

    /**
     * The account this surface is allowed to act on, or null.
     *
     * Scoped to the `member` role for the same reason password reset is: this
     * table is shared with the Fortify dashboard. Scoped to `status ===
     * 'deactivated'` on top of that, which is what makes a `blocked` account,
     * an `active` account, and a nonexistent phone all resolve to the same
     * null here — a blocked account must only ever be reversible by an admin,
     * and an already-active account has nothing to reactivate.
     */
    private function memberFor(string $phone): ?User
    {
        return User::where('phone', $phone)->role('member')->where('status', 'deactivated')->first();
    }
}
