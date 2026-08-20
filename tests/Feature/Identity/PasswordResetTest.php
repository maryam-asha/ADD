<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Models\User;
use App\Services\Auth\TokenPairService;
use App\Services\Otp\OtpService;
use Illuminate\Testing\TestResponse;
use Tests\Support\InteractsWithOtp;

/**
 * Recovery is the one flow that changes a credential without presenting the
 * old one, so it has to assume the old one is already in the wrong hands:
 * every session opened under it dies the moment the password changes.
 *
 * Three calls now, mirroring the sign-up screens: forgot (send code), verify
 * (spend the code for a one-time reset_token), reset (spend that token for
 * the new password). The raw code never reaches the third call — verify()
 * marks it verified_at, which is why reset() needs a token instead.
 */
class PasswordResetTest extends IdentityTestCase
{
    use InteractsWithOtp;

    private const PHONE = '+963912345678';

    private const OLD_PASSWORD = 'old-password';

    private const NEW_PASSWORD = 'brand-new-password';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeOtpProvider();
    }

    private function member(): User
    {
        $user = User::factory()->create([
            'phone' => self::PHONE,
            'password' => self::OLD_PASSWORD,
        ]);

        $user->assignRole('member');

        return $user;
    }

    private function verify(?string $code = null, string $phone = self::PHONE): TestResponse
    {
        return $this->postJson('/api/v1/auth/password/verify', [
            'phone' => $phone,
            'code' => $code ?? $this->otpProvider->lastCodeFor($phone),
        ]);
    }

    /**
     * The full happy-path flow: forgot -> verify -> reset. Overrides land on
     * the final reset() call only — pass 'reset_token' to replace the one this
     * helper mints for itself.
     */
    private function reset(array $overrides = []): TestResponse
    {
        $this->postJson('/api/v1/auth/password/forgot', ['phone' => self::PHONE])->assertOk();

        $resetToken = $this->verify()->assertOk()->json('reset_token');

        return $this->postJson('/api/v1/auth/password/reset', array_merge([
            'phone' => self::PHONE,
            'reset_token' => $resetToken,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], $overrides));
    }

    public function test_a_member_resets_their_password_and_signs_in_with_the_new_one(): void
    {
        $this->member();

        $this->reset()->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'phone' => self::PHONE,
            'password' => self::NEW_PASSWORD,
        ])->assertOk();
    }

    public function test_the_old_password_stops_working(): void
    {
        $this->member();

        $this->reset()->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'phone' => self::PHONE,
            'password' => self::OLD_PASSWORD,
        ])->assertStatus(401);
    }

    /**
     * Item 8's whole point, checked the only way that proves it: by making a
     * real authenticated request with a token minted before the reset.
     */
    public function test_every_access_token_issued_before_the_reset_is_dead(): void
    {
        $user = $this->member();
        $pair = app(TokenPairService::class)->issue($user)->toArray();

        $this->withHeader('Authorization', 'Bearer '.$pair['access_token'])
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->reset()->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Guards resolve a user once and cache it for the life of the
        // container. Production gets a fresh container per request; a test
        // making two requests in a row does not, so without this the second
        // call would be answered from the first one's cached user and the
        // assertion would pass no matter what the token did.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$pair['access_token'])
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_every_refresh_token_issued_before_the_reset_is_dead(): void
    {
        $user = $this->member();
        $pair = app(TokenPairService::class)->issue($user)->toArray();

        $this->reset()->assertOk();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])
            ->assertStatus(401);
    }

    public function test_sessions_on_every_device_are_cut_not_just_one(): void
    {
        $user = $this->member();
        $phone = app(TokenPairService::class)->issue($user)->toArray();
        $laptop = app(TokenPairService::class)->issue($user)->toArray();

        $this->reset()->assertOk();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $phone['refresh_token']])->assertStatus(401);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $laptop['refresh_token']])->assertStatus(401);
    }

    /**
     * The reset deliberately does not log the member in — they re-enter the
     * password they just chose, which is also the first confirmation they
     * typed it as intended.
     */
    public function test_the_reset_itself_issues_no_token(): void
    {
        $this->member();

        $response = $this->reset();

        $response->assertOk();
        $response->assertJsonMissingPath('access_token');
        $response->assertJsonMissingPath('refresh_token');
    }

    public function test_forgot_answers_an_unknown_number_exactly_as_a_known_one(): void
    {
        $this->member();

        $known = $this->postJson('/api/v1/auth/password/forgot', ['phone' => self::PHONE]);
        $unknown = $this->postJson('/api/v1/auth/password/forgot', ['phone' => '+963999999999']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_forgot_sends_nothing_to_an_unknown_number(): void
    {
        $this->postJson('/api/v1/auth/password/forgot', ['phone' => '+963999999999'])->assertOk();

        $this->assertNull($this->otpProvider->lastCodeFor('+963999999999'));
        $this->assertDatabaseCount('otp_verifications', 0);
    }

    /**
     * verify() fails exactly as silently as forgot() about who has an
     * account — a phone with no member never had a code minted for it, so
     * this is the backstop, not the gate.
     */
    public function test_verify_fails_silently_for_a_phone_with_no_member_account(): void
    {
        $response = $this->verify('000000', '+963999999999');

        $response->assertStatus(422);
        $response->assertJsonPath('message', __('api.auth.code_invalid'));
    }

    /**
     * Item 11(e), the other direction: a code minted for signing up must not
     * be spendable on taking over an existing account's password.
     */
    public function test_a_registration_code_cannot_be_spent_on_a_password_reset_verification(): void
    {
        $this->member();

        // Minted through the service rather than /auth/register, which now
        // refuses a number that already has an account. The realistic path to
        // this state is a sign-up code issued while the number was still free,
        // still live when the account came into existence.
        app(OtpService::class)->request(self::PHONE, OtpPurpose::Registration);
        $code = $this->otpProvider->lastCodeFor(self::PHONE);

        $response = $this->verify($code);

        $response->assertStatus(422);
        $response->assertJsonPath('message', __('api.auth.code_purpose_mismatch_registration'));

        $this->postJson('/api/v1/auth/login', [
            'phone' => self::PHONE,
            'password' => self::OLD_PASSWORD,
        ])->assertOk();
    }

    public function test_an_invalid_code_is_rejected_by_verify(): void
    {
        $this->member();

        $this->postJson('/api/v1/auth/password/forgot', ['phone' => self::PHONE])->assertOk();

        $this->verify('000000')->assertStatus(422);

        $this->postJson('/api/v1/auth/login', [
            'phone' => self::PHONE,
            'password' => self::OLD_PASSWORD,
        ])->assertOk();
    }

    public function test_a_code_cannot_be_verified_twice(): void
    {
        $this->member();

        $this->postJson('/api/v1/auth/password/forgot', ['phone' => self::PHONE])->assertOk();
        $code = $this->otpProvider->lastCodeFor(self::PHONE);

        $this->verify($code)->assertOk();
        $this->verify($code)->assertStatus(422);
    }

    /**
     * The reset_token minted by verify() is single-use independent of the
     * code that produced it — spending it once for a real reset must not
     * leave it good for a second one.
     */
    public function test_a_reset_token_cannot_be_spent_twice(): void
    {
        $this->member();

        $this->postJson('/api/v1/auth/password/forgot', ['phone' => self::PHONE])->assertOk();
        $token = $this->verify()->assertOk()->json('reset_token');

        $this->postJson('/api/v1/auth/password/reset', [
            'phone' => self::PHONE,
            'reset_token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'phone' => self::PHONE,
            'reset_token' => $token,
            'password' => 'third-password',
            'password_confirmation' => 'third-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', __('api.auth.reset_token_invalid'));
    }

    public function test_reset_rejects_a_missing_reset_token(): void
    {
        $this->member();

        $this->postJson('/api/v1/auth/password/reset', [
            'phone' => self::PHONE,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('reset_token');
    }

    public function test_reset_rejects_a_garbage_reset_token(): void
    {
        $this->member();

        $response = $this->reset(['reset_token' => 'not-a-real-token']);

        $response->assertStatus(422);
        $response->assertJsonPath('message', __('api.auth.reset_token_invalid'));
    }

    public function test_reset_rejects_an_expired_reset_token(): void
    {
        $this->member();

        $this->postJson('/api/v1/auth/password/forgot', ['phone' => self::PHONE])->assertOk();
        $token = $this->verify()->assertOk()->json('reset_token');

        $this->travel(config('otp.reset_token_ttl_minutes') + 1)->minutes();

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'phone' => self::PHONE,
            'reset_token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', __('api.auth.reset_token_invalid'));
    }

    /**
     * The raw OTP code is not an acceptable substitute for the reset_token
     * verify() hands back — the two are different secrets on purpose.
     */
    public function test_reset_no_longer_accepts_the_raw_otp_code_in_place_of_a_token(): void
    {
        $this->member();

        $this->postJson('/api/v1/auth/password/forgot', ['phone' => self::PHONE])->assertOk();
        $code = $this->otpProvider->lastCodeFor(self::PHONE);
        $this->verify($code)->assertOk();

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'phone' => self::PHONE,
            'reset_token' => $code,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', __('api.auth.reset_token_invalid'));
    }

    public function test_the_new_password_must_meet_the_minimum_length(): void
    {
        $this->member();

        $this->reset(['password' => 'short12', 'password_confirmation' => 'short12'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_the_new_password_must_be_confirmed(): void
    {
        $this->member();

        $this->reset(['password_confirmation' => 'mismatched'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }
}
