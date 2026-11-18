<?php

namespace App\Services\Otp;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Models\OtpVerification;
use App\Services\Otp\Exceptions\OtpDeliveryFailedException;
use App\Services\Otp\Exceptions\OtpThrottledException;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class OtpService
{
    private const CHANNEL = 'whatsapp';

    public function __construct(private readonly OtpProvider $provider) {}

    /**
     * Generate, persist, and dispatch a fresh code for the given phone.
     *
     * `$purpose` has no default on purpose: it used to, back when one generic
     * endpoint served both flows and an absent purpose meant registration.
     * Each flow has its own endpoint now, so every caller knows exactly what it
     * is minting and there is no case left where guessing would be right.
     *
     * `$alongside` runs in the same short transaction that stores the code,
     * for a caller whose own row must be written with it (register() parks the
     * profile this way) — never wrap request() in a transaction instead. The
     * send is an HTTP call to the gateway that can take several seconds, and
     * holding a transaction open across it is what let MySQL drop the
     * connection mid-request ("server has gone away") in production.
     *
     * @param  (Closure(): void)|null  $alongside
     *
     * @throws OtpThrottledException if a code was requested too recently —
     *                               checked before anything is written.
     * @throws OtpDeliveryFailedException if the gateway did not accept the send.
     *                                    The code row is dropped and the cooldown
     *                                    is not started, so the member can retry.
     */
    public function request(string $phone, OtpPurpose $purpose, ?Closure $alongside = null): OtpVerification
    {
        $key = $this->throttleKey($phone, $purpose);

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 1)) {
            throw new OtpThrottledException(RateLimiter::availableIn($key));
        }

        $code = $this->generateCode();

        $otp = DB::transaction(function () use ($phone, $purpose, $code, $alongside) {
            if ($alongside) {
                $alongside();
            }

            return OtpVerification::create([
                'phone' => $phone,
                'provider' => self::CHANNEL,
                'purpose' => $purpose,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(config('otp.expires_after_minutes')),
            ]);
        });

        if (! $this->provider->send($phone, $code, self::CHANNEL)) {
            $otp->delete();

            throw new OtpDeliveryFailedException;
        }

        RateLimiter::hit($key, config('otp.resend_cooldown_seconds'));

        return $otp;
    }

    /**
     * Verify a submitted code against the latest unverified code issued to
     * this phone for this purpose. Burns the attempt whether or not the code
     * matches, up to the configured max.
     *
     * A code that is genuine but was issued for the *other* flow comes back as
     * PurposeMismatch rather than silently failing, so the member can be told
     * which screen their code belongs to instead of retyping a code that was
     * never going to work here.
     */
    public function verify(string $phone, string $code, OtpPurpose $purpose): OtpResult
    {
        $outstanding = OtpVerification::where('phone', $phone)
            ->whereNull('verified_at')
            ->latest('id')
            ->get();

        // The newest live code of the purpose being claimed is the one this
        // attempt is spent against, and the only one whose counter burns —
        // otherwise a wrong guess here would eat the other flow's attempts too.
        $target = $outstanding->firstWhere('purpose', $purpose);

        if ($target && $this->isLive($target)) {
            $target->increment('attempts');

            if (Hash::check($code, $target->code_hash)) {
                $target->update(['verified_at' => now()]);

                return OtpResult::Verified;
            }
        }

        // Only once the claimed purpose has failed outright do we ask whether
        // the code was real but issued elsewhere. Checked in that order on
        // purpose: answering "wrong flow" before the code itself is known good
        // would confirm to anyone guessing that a live code exists for this
        // phone.
        foreach ($outstanding as $otp) {
            if ($otp->purpose !== $purpose && $this->isLive($otp) && Hash::check($code, $otp->code_hash)) {
                return OtpResult::PurposeMismatch;
            }
        }

        return OtpResult::InvalidOrExpired;
    }

    /**
     * Mark the row `verify()` just stamped as authorized to reset the password.
     * The authorization is time-limited and single-use — `consumeResetAuthorization()`
     * clears it the moment it is spent, whether the reset succeeds or not.
     *
     * Only ever called immediately after `verify()` has returned
     * OtpResult::Verified for this exact phone/purpose.
     */
    public function authorizeReset(string $phone, OtpPurpose $purpose): void
    {
        OtpVerification::where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNotNull('verified_at')
            ->latest('id')
            ->firstOrFail()
            ->update([
                'password_reset_authorized_at' => now()->addMinutes(config('otp.reset_token_ttl_minutes')),
            ]);
    }

    /**
     * Spend the authorization minted by authorizeReset(). Single-use: the
     * timestamp is cleared on first match, so the same window cannot be spent
     * twice independent of what the caller does with the result.
     */
    public function consumeResetAuthorization(string $phone, OtpPurpose $purpose): bool
    {
        $otp = OtpVerification::where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNotNull('verified_at')
            ->where('password_reset_authorized_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp) {
            return false;
        }

        $otp->update(['password_reset_authorized_at' => null]);

        return true;
    }

    private function isLive(OtpVerification $otp): bool
    {
        return ! $otp->expires_at->isPast()
            && $otp->attempts < config('otp.max_verify_attempts');
    }

    private function generateCode(): string
    {
        if ($fixed = config('otp.fixed_code')) {
            return $fixed;
        }

        $length = config('otp.code_length');

        return (string) random_int(10 ** ($length - 1), (10 ** $length) - 1);
    }

    /**
     * Scoped per purpose: a member who just triggered a registration code must
     * still be able to ask for a password reset inside the same cooldown
     * window — the two flows aren't alternatives, and someone locked out of
     * one shouldn't be locked out of the other.
     */
    private function throttleKey(string $phone, OtpPurpose $purpose): string
    {
        return "otp-request:{$purpose->value}:{$phone}";
    }
}
