<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Models\PendingRegistration;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\RegisterVerifyRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\Exceptions\InvalidRefreshTokenException;
use App\Services\Auth\TokenPairService;
use App\Services\Otp\Exceptions\OtpThrottledException;
use App\Services\Otp\OtpResult;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class MemberAuthController extends Controller
{
    private static ?string $unmatchableHash = null;

    public function __construct(
        private readonly OtpService $otp,
        private readonly TokenPairService $tokens,
    ) {}

    /**
     * Step one of sign-up: validate the profile, park it, send a code. Nothing
     * lands in `users` — an unverified submission must never be an account.
     *
     * The purpose is fixed here rather than taken from the body. This endpoint
     * only ever means one thing, and a caller who could name its own purpose
     * could mint a password-reset code for a number it doesn't own.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');

        try {
            // One transaction so the parked profile and the live code are never
            // out of step. A throttled request rolls the upsert back rather than
            // leaving a profile the member has no code to unlock — and rather
            // than letting an older code unlock a newer profile.
            DB::transaction(function () use ($phone, $request) {
                PendingRegistration::updateOrCreate(['phone' => $phone], [
                    'name' => $request->validated('name'),
                    'email' => $request->validated('email'),
                    'password' => $request->validated('password'),
                    'expires_at' => now()->addMinutes(config('otp.expires_after_minutes')),
                ]);

                $this->otp->request($phone, OtpPurpose::Registration);
            });
        } catch (OtpThrottledException $e) {
            return response()->json([
                'message' => __('api.auth.otp_request_throttled'),
                'retry_after' => $e->retryAfterSeconds,
            ], 429);
        }

        return response()->json(['message' => __('api.auth.otp_sent')]);
    }

    /**
     * Step two: spend the code and create the account from the profile step one
     * parked. Registration only — before the hybrid switch this doubled as the
     * login for anyone who already had an account, and it doesn't any more,
     * because leaving that door open would mean the password set here could
     * always be bypassed by asking for another code.
     *
     * Everything knowable without the code is checked before the code, because
     * codes are one-time: burning one to report a failure it had no part in
     * would leave the member waiting out a resend cooldown for nothing.
     */
    public function verifyRegistration(RegisterVerifyRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');

        $pending = PendingRegistration::where('phone', $phone)->first();

        // No parked profile means step one never ran, or it expired alongside
        // the code it was issued with. Same answer either way — there is nothing
        // here to build an account from.
        if (! $pending || ! $pending->isUsable()) {
            return response()->json([
                'message' => __('api.auth.registration_code_invalid'),
            ], 422);
        }

        // Step one already cleared this number and email, so a conflict here
        // means one of them was claimed in the minutes since: a conflict on the
        // account being created rather than a malformed request, hence 409 and
        // not a validation error. The email half matters as much as the phone —
        // without it the insert would hit that unique index and surface as a 500.
        //
        // withTrashed() because both unique indexes still cover a soft-deleted
        // row.
        if ($this->phoneOrEmailIsTaken($pending)) {
            return response()->json([
                'message' => __('api.auth.account_already_exists'),
            ], 409);
        }

        $result = $this->otp->verify($phone, $request->validated('code'), OtpPurpose::Registration);

        if ($result === OtpResult::PurposeMismatch) {
            return response()->json([
                'message' => __('api.auth.code_purpose_mismatch_reset'),
            ], 422);
        }

        if ($result !== OtpResult::Verified) {
            return response()->json(['message' => __('api.auth.code_invalid')], 422);
        }

        $user = DB::transaction(function () use ($pending) {
            $user = User::create([
                'phone' => $pending->phone,
                'name' => $pending->name,
                'email' => $pending->email,
                // Already a hash — the `hashed` cast on both models leaves an
                // existing hash alone rather than hashing it twice.
                'password' => $pending->password,
                'preferred_language' => 'ar',
                'status' => 'active',
            ]);

            $user->assignRole('member');

            // Provisioned empty. Balance is derived from transactions rather
            // than stored (docs/decisions/phase-3-membership-plan-wallet-mechanics.md),
            // so "zero balance" is a wallet with nothing posted to it.
            Wallet::create([
                'owner_type' => OwnerType::User,
                'owner_id' => $user->id,
            ]);

            // The sign-up is no longer pending. Dropped in the same transaction
            // that creates the account, so the two states can never both exist.
            $pending->delete();

            return $user;
        });

        return response()->json([
            ...$this->tokens->issue($user)->toArray(),
            'user' => new UserResource($user),
        ]);
    }

    /**
     * The everyday sign-in. Issues the identical pair registration does, via
     * the same service — the two must never drift into issuing different
     * things.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('phone', $request->validated('phone'))->first();

        if (! $this->passwordMatches($user, $request->validated('password'))) {
            return $this->credentialsRejected();
        }

        /*
         * The member app is not a way into operations.
         *
         * One `users` table and one `password` column serve both surfaces, but
         * they are not equally guarded: the dashboard puts Fortify's second
         * factor in front of an operator's account and this endpoint has no
         * second factor at all. Without this check, an operator's phone and
         * password alone would mint an API token that reaches the operations
         * API — making the weaker door the one that decides how well the
         * stronger one is protected.
         *
         * Answered with the same rejection as a wrong password, deliberately:
         * "you are staff, use the dashboard" would tell an attacker which
         * numbers belong to operators, which are precisely the accounts worth
         * targeting.
         */
        if (! $user->hasRole('member')) {
            return $this->credentialsRejected();
        }

        // Only reached by someone who has already proven they hold the
        // password, so naming the account state here reveals nothing they
        // couldn't see by logging in — and a member locked out with no reason
        // given just calls support.
        if ($user->status !== 'active') {
            return response()->json([
                'message' => __('api.auth.account_inactive'),
                'status' => $user->status,
            ], 403);
        }

        return response()->json([
            ...$this->tokens->issue($user)->toArray(),
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Whether either unique identifier on the parked profile has been claimed
     * since step one cleared it.
     */
    private function phoneOrEmailIsTaken(PendingRegistration $pending): bool
    {
        return User::withTrashed()
            ->where('phone', $pending->phone)
            ->orWhere(fn ($query) => $query
                ->whereNotNull('email')
                ->where('email', $pending->email))
            ->exists();
    }

    /**
     * The single rejection every failed sign-in returns — unknown number,
     * wrong password, or an account that has no business on this surface.
     * Built in one place so the three can never drift into being tellable
     * apart by their wording or status.
     */
    private function credentialsRejected(): JsonResponse
    {
        return response()->json(['message' => __('api.auth.invalid_credentials')], 401);
    }

    /**
     * A missing user and a missing password hash both mean "no", but both must
     * cost the same as a real comparison. Returning early on either would leave
     * the response *time* telling apart the two cases the response *body* is
     * carefully written not to distinguish.
     */
    private function passwordMatches(?User $user, string $password): bool
    {
        if ($user?->password === null) {
            Hash::check($password, self::unmatchableHash());

            return false;
        }

        return Hash::check($password, $user->password);
    }

    /**
     * A hash of something nobody knows, at whatever cost the app is configured
     * for. Built once per process rather than hard-coded so it can never drift
     * out of step with config('hashing').
     */
    private static function unmatchableHash(): string
    {
        return self::$unmatchableHash ??= Hash::make(Str::random(40));
    }

    /**
     * Trade a refresh token for a new pair. Unauthenticated on purpose — the
     * whole point is to be reachable once the access token has expired, and
     * the refresh token is itself the credential being presented.
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        try {
            $pair = $this->tokens->rotate($request->validated('refresh_token'));
        } catch (InvalidRefreshTokenException) {
            return response()->json(['message' => __('api.auth.refresh_token_invalid')], 401);
        }

        return response()->json($pair->toArray());
    }

    /**
     * Ends the caller's session, whichever credential they arrived with.
     *
     * `auth:sanctum` accepts a session cookie as readily as a bearer token — it
     * is how the dashboard authenticates — so this route is reachable by a
     * session-authenticated user even though the member app always holds a
     * token. Sanctum represents that caller with a `TransientToken`, which is
     * not a token row and cannot be deleted; reaching for one used to be a 500.
     *
     * Their session is ended rather than the request being refused. Anyone who
     * asks to be logged out should be, and answering 200 while leaving a live
     * cookie behind would be the more dangerous of the two lies.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $this->tokens->revokeSession($token);
        } else {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return response()->json(['message' => __('api.auth.logged_out')]);
    }
}
