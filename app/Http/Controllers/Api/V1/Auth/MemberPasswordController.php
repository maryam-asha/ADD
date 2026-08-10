<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Auth\TokenPairService;
use App\Services\Otp\Exceptions\OtpThrottledException;
use App\Services\Otp\OtpResult;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Recovery for members who signed up with a phone and a password. Separate
 * from Fortify's email-based reset, which serves the operations dashboard —
 * members have no email requirement, so the WhatsApp code is the only channel
 * that reliably reaches them.
 */
class MemberPasswordController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly TokenPairService $tokens,
    ) {}

    /**
     * Always answers the same way, whether or not the number has an account.
     *
     * The throttle exception is swallowed rather than surfaced for the same
     * reason: a 429 on the second call would mark the numbers that actually
     * received something, and a plain 200 on the rest. The cooldown still does
     * its job — the code simply isn't re-sent — and route-level throttling
     * covers outright abuse of the endpoint.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');

        if ($this->memberFor($phone)) {
            try {
                $this->otp->request($phone, OtpPurpose::PasswordReset);
            } catch (OtpThrottledException) {
                // Intentionally indistinguishable from the success path.
            }
        }

        return response()->json([
            'message' => 'If that number has an account, a reset code has been sent to it.',
        ]);
    }

    /**
     * Sets the new password and ends every session the account has open. The
     * revocation is the point, not housekeeping: whoever prompted the reset may
     * already hold the old password, and a session opened with it would
     * otherwise outlive the credential it was granted under.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');

        $user = $this->memberFor($phone);

        // Checked before the code is spent, and answered as an invalid code so
        // the endpoint stays as silent as forgot() about who has an account
        // here. forgot() never mints a code for a non-member, so this should be
        // unreachable — it is the backstop, not the gate.
        if (! $user) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $result = $this->otp->verify($phone, $request->validated('code'), OtpPurpose::PasswordReset);

        if ($result === OtpResult::PurposeMismatch) {
            return response()->json([
                'message' => 'That code was issued to create an account, not to reset a password.',
            ], 422);
        }

        if ($result !== OtpResult::Verified) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        DB::transaction(function () use ($user, $request) {
            $user->forceFill(['password' => $request->validated('password')])->save();

            $this->tokens->revokeAll($user);
        });

        return response()->json([
            'message' => 'Password updated. Please log in with your new password.',
        ]);
    }

    /**
     * The account this surface is allowed to act on, or null.
     *
     * Scoped to the `member` role for the same reason login is: `password` is a
     * single column shared with the Fortify dashboard, so a reset driven from
     * here would rewrite an operator's dashboard credential — reachable by
     * whoever controls that operator's phone, and bypassing the email-based
     * reset the dashboard actually uses.
     */
    private function memberFor(string $phone): ?User
    {
        return User::where('phone', $phone)->role('member')->first();
    }
}
