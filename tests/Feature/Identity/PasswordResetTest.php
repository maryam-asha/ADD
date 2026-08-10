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
 */
class PasswordResetTest extends IdentityTestCase
{
    use InteractsWithOtp;

    private const PHONE = '0912345678';

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

    private function reset(array $overrides = []): TestResponse
    {
        $this->postJson('/api/v1/auth/password/forgot', ['phone' => self::PHONE])->assertOk();

        return $this->postJson('/api/v1/auth/password/reset', array_merge([
            'phone' => self::PHONE,
            'code' => $this->otpProvider->lastCodeFor(self::PHONE),
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
        $unknown = $this->postJson('/api/v1/auth/password/forgot', ['phone' => '0999999999']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_forgot_sends_nothing_to_an_unknown_number(): void
    {
        $this->postJson('/api/v1/auth/password/forgot', ['phone' => '0999999999'])->assertOk();

        $this->assertNull($this->otpProvider->lastCodeFor('0999999999'));
        $this->assertDatabaseCount('otp_verifications', 0);
    }

    /**
     * Item 11(e), the other direction: a code minted for signing up must not
     * be spendable on taking over an existing account's password.
     */
    public function test_a_registration_code_cannot_be_spent_on_a_reset(): void
    {
        $this->member();

        // Minted through the service rather than /auth/register, which now
        // refuses a number that already has an account. The realistic path to
        // this state is a sign-up code issued while the number was still free,
        // still live when the account came into existence.
        app(OtpService::class)->request(self::PHONE, OtpPurpose::Registration);
        $code = $this->otpProvider->lastCodeFor(self::PHONE);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'phone' => self::PHONE,
            'code' => $code,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $response->assertStatus(422);

        $this->postJson('/api/v1/auth/login', [
            'phone' => self::PHONE,
            'password' => self::OLD_PASSWORD,
        ])->assertOk();
    }

    public function test_an_invalid_code_is_rejected(): void
    {
        $this->member();

        $this->reset(['code' => '000000'])->assertStatus(422);

        $this->postJson('/api/v1/auth/login', [
            'phone' => self::PHONE,
            'password' => self::OLD_PASSWORD,
        ])->assertOk();
    }

    public function test_a_code_cannot_be_spent_on_a_reset_twice(): void
    {
        $this->member();

        $this->postJson('/api/v1/auth/password/forgot', ['phone' => self::PHONE])->assertOk();
        $code = $this->otpProvider->lastCodeFor(self::PHONE);

        $this->postJson('/api/v1/auth/password/reset', [
            'phone' => self::PHONE,
            'code' => $code,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->postJson('/api/v1/auth/password/reset', [
            'phone' => self::PHONE,
            'code' => $code,
            'password' => 'third-password',
            'password_confirmation' => 'third-password',
        ])->assertStatus(422);
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
