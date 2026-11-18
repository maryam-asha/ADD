<?php

namespace App\Http\Controllers\Api\V1\Kiosk;

use App\Domain\Booking\Services\KioskPairingService;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\KioskLoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * The kiosk app's setup login: staff prove who they are before the app shows
 * its pairing-code screen. It checks what the dashboard checks, second factor
 * included, because the same accounts sign in here and a weaker door would
 * decide how well the stronger one protects them. What it hands out is much
 * smaller than a dashboard session: a short token that can only pair a kiosk.
 */
class KioskAuthController extends Controller
{
    private static ?string $unmatchableHash = null;

    public function login(
        KioskLoginRequest $request,
        KioskPairingService $pairings,
        TwoFactorAuthenticationProvider $twoFactor,
    ): JsonResponse {
        $user = User::where('email', Str::lower($request->validated('email')))->first();

        // A missing account costs the same as a real comparison, so the
        // response time cannot tell the refusals below apart either.
        $hash = $user?->password ?? (self::$unmatchableHash ??= Hash::make(Str::random(40)));

        // One answer for all of them: naming the permission would tell a
        // guesser that the password was right.
        if (! Hash::check($request->validated('password'), $hash)
            || $user === null
            || $user->status !== 'active'
            || ! $user->can(KioskPairingService::PAIR_PERMISSION)) {
            return response()->json(['message' => __('api.auth.invalid_credentials')], 401);
        }

        $this->ensureSecondFactor($user, $request->validated('two_factor_code'), $twoFactor);

        $token = $pairings->issueOperatorToken($user);

        return response()->json(['data' => [
            'access_token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at->toIso8601String(),
        ]])->header('Cache-Control', 'no-store');
    }

    /**
     * Reached only with the right password, so asking for the code gives
     * nothing away, and the app learns to show the code field from the error.
     */
    private function ensureSecondFactor(User $user, ?string $code, TwoFactorAuthenticationProvider $twoFactor): void
    {
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return;
        }

        if ($code === null) {
            throw ValidationException::withMessages(['two_factor_code' => __('api.kiosk.two_factor_required')]);
        }

        if (! $twoFactor->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code)) {
            throw ValidationException::withMessages(['two_factor_code' => __('api.kiosk.invalid_two_factor_code')]);
        }
    }
}
