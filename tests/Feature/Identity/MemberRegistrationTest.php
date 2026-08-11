<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Models\OtpVerification;
use App\Domain\Identity\Models\PendingRegistration;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Services\Otp\OtpService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Support\InteractsWithOtp;

/**
 * Sign-up is two steps against two endpoints. `register` validates the whole
 * profile, parks it, and sends a code; `register/verify` needs nothing but the
 * phone and that code, because the profile is already held server-side.
 *
 * Nothing lands in `users` until the code comes back, so an abandoned sign-up
 * leaves no account and no phone number held by someone who never proved they
 * own it — the parked row expires on its own.
 */
class MemberRegistrationTest extends IdentityTestCase
{
    use InteractsWithOtp;

    private const PHONE = '0912345678';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeOtpProvider();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'phone' => self::PHONE,
            'name' => 'Maryam Asha',
            'email' => 'maryam@example.com',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function start(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/register', $this->payload($overrides));
    }

    private function verify(?string $code = null, string $phone = self::PHONE): TestResponse
    {
        return $this->postJson('/api/v1/auth/register/verify', [
            'phone' => $phone,
            'code' => $code ?? $this->otpProvider->lastCodeFor($phone),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function register(array $overrides = []): TestResponse
    {
        $this->startRegistration($this->payload($overrides));

        return $this->verify();
    }

    public function test_a_new_member_registers_with_a_password_and_receives_a_token_pair(): void
    {
        $response = $this->register();

        $response->assertOk();
        $response->assertJsonStructure(['access_token', 'refresh_token', 'token_type', 'expires_in', 'user']);
        $response->assertJsonPath('user.phone', self::PHONE);
        $response->assertJsonPath('user.name', 'Maryam Asha');

        $user = User::where('phone', self::PHONE)->sole();
        $this->assertTrue(Hash::check('correct-horse', $user->password));
    }

    /**
     * Regression: `User::create()` in `verifyRegistration()` used to omit
     * `preferred_currency`, so this response showed null even though the DB
     * column defaults to 'SYP' — Eloquent doesn't re-fetch DB-side defaults
     * into an unrefreshed in-memory model. Asserts the response body
     * directly, not a re-fetch, since that's what was actually wrong.
     */
    public function test_the_registration_response_includes_the_syp_currency_default(): void
    {
        $response = $this->register();

        $response->assertOk();
        $response->assertJsonPath('user.preferred_currency', 'SYP');
    }

    /**
     * The point of parking the profile: step two carries the code and nothing
     * else. No re-typing, and the password crosses the wire once.
     */
    public function test_step_two_needs_only_the_phone_and_the_code(): void
    {
        $code = $this->startRegistration($this->payload());

        $response = $this->postJson('/api/v1/auth/register/verify', [
            'phone' => self::PHONE,
            'code' => $code,
        ]);

        $response->assertOk();

        $user = User::where('phone', self::PHONE)->sole();
        $this->assertSame('Maryam Asha', $user->name);
        $this->assertSame('maryam@example.com', $user->email);
        $this->assertTrue(Hash::check('correct-horse', $user->password));
    }

    public function test_the_parked_password_is_stored_hashed(): void
    {
        $this->start()->assertOk();

        $parked = PendingRegistration::where('phone', self::PHONE)->sole();

        $this->assertNotSame('correct-horse', $parked->password);
        $this->assertTrue(Hash::check('correct-horse', $parked->password));
    }

    public function test_the_parked_row_is_discarded_once_the_account_exists(): void
    {
        $this->register()->assertOk();

        $this->assertDatabaseCount('pending_registrations', 0);
    }

    /**
     * Correcting a typo means running step one again. The newest submission is
     * the one the code belongs to, so it has to be the one that wins.
     */
    public function test_starting_again_replaces_the_parked_profile(): void
    {
        $this->start(['name' => 'Wrong Name'])->assertOk();

        $this->travel(config('otp.resend_cooldown_seconds') + 1)->seconds();
        $code = $this->startRegistration($this->payload(['name' => 'Right Name']));

        $this->verify($code)->assertOk();

        $this->assertSame('Right Name', User::where('phone', self::PHONE)->sole()->name);
        $this->assertDatabaseCount('pending_registrations', 0);
    }

    public function test_verifying_without_having_started_is_rejected(): void
    {
        app(OtpService::class)->request(self::PHONE, OtpPurpose::Registration);

        $this->verify()->assertStatus(422);

        $this->assertDatabaseMissing('users', ['phone' => self::PHONE]);
    }

    public function test_registration_assigns_the_member_role_and_provisions_a_wallet(): void
    {
        $this->register()->assertOk();

        $user = User::where('phone', self::PHONE)->sole();

        $this->assertTrue($user->hasRole('member'));
        $this->assertFalse($user->hasRole('operations'));

        $wallet = Wallet::where('owner_type', OwnerType::User)->where('owner_id', $user->id)->sole();
        $this->assertSame(0, $wallet->transactions()->count());
    }

    public function test_the_issued_pair_is_immediately_usable(): void
    {
        $response = $this->register();

        $this->withHeader('Authorization', 'Bearer '.$response->json('access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $response->json('refresh_token')])
            ->assertOk();
    }

    // ---------------------------------------------------------------------
    // Step one rejects everything it can before spending a WhatsApp message,
    // and parks nothing when it does.
    // ---------------------------------------------------------------------

    public function test_an_already_registered_phone_is_refused_before_any_code_is_sent(): void
    {
        User::factory()->create(['phone' => self::PHONE]);

        $this->start()->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->assertNull($this->otpProvider->lastCodeFor(self::PHONE));
        $this->assertDatabaseCount('otp_verifications', 0);
        $this->assertDatabaseCount('pending_registrations', 0);
    }

    public function test_a_taken_email_is_refused_before_any_code_is_sent(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->start(['email' => 'taken@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('pending_registrations', 0);
    }

    public function test_a_short_password_is_refused_before_any_code_is_sent(): void
    {
        $this->start(['password' => 'short12', 'password_confirmation' => 'short12'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('otp_verifications', 0);
        $this->assertDatabaseCount('pending_registrations', 0);
    }

    public function test_a_mismatched_confirmation_is_refused_before_any_code_is_sent(): void
    {
        $this->start(['password_confirmation' => 'something-else'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('pending_registrations', 0);
    }

    public function test_a_missing_name_is_refused_before_any_code_is_sent(): void
    {
        $this->start(['name' => null])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('pending_registrations', 0);
    }

    public function test_the_email_is_optional(): void
    {
        $this->register(['email' => null])->assertOk();

        $this->assertNull(User::where('phone', self::PHONE)->sole()->email);
    }

    /**
     * The purpose is the endpoint's to decide, not the caller's.
     */
    public function test_the_dispatched_code_is_always_a_registration_code(): void
    {
        $this->start(['purpose' => 'password_reset'])->assertOk();

        $this->assertSame(
            OtpPurpose::Registration,
            OtpVerification::where('phone', self::PHONE)->sole()->purpose
        );
    }

    // ---------------------------------------------------------------------
    // Step two.
    // ---------------------------------------------------------------------

    public function test_an_invalid_code_is_rejected(): void
    {
        $this->startRegistration($this->payload());

        $this->verify('000000')->assertStatus(422);

        $this->assertDatabaseMissing('users', ['phone' => self::PHONE]);
        $this->assertDatabaseCount('pending_registrations', 1);
    }

    public function test_a_password_reset_code_cannot_be_spent_on_registration(): void
    {
        $this->startRegistration($this->payload());

        // A live reset code for the same number, newer than the sign-up one.
        app(OtpService::class)->request(self::PHONE, OtpPurpose::PasswordReset);
        $resetCode = $this->otpProvider->lastCodeFor(self::PHONE);

        $this->verify($resetCode)->assertStatus(422);

        $this->assertDatabaseMissing('users', ['phone' => self::PHONE]);
    }

    /**
     * Item 9, now reachable only as a race: the number was free at step one and
     * was claimed before step two came back.
     */
    public function test_a_phone_claimed_between_the_two_steps_yields_409(): void
    {
        $code = $this->startRegistration($this->payload());

        User::factory()->create(['phone' => self::PHONE]);

        $response = $this->verify($code);

        $response->assertStatus(409);
        $response->assertJsonMissingPath('access_token');
    }

    /**
     * The same race on the other unique column. Without this the insert would
     * hit the email index and surface as a 500.
     */
    public function test_an_email_claimed_between_the_two_steps_yields_409(): void
    {
        $code = $this->startRegistration($this->payload());

        User::factory()->create(['email' => 'maryam@example.com']);

        $response = $this->verify($code);

        $response->assertStatus(409);
        $this->assertDatabaseMissing('users', ['phone' => self::PHONE]);
    }

    /**
     * A conflict is knowable without spending the code, so it is checked
     * first — burning a one-time code to report something the code had no part
     * in would leave the member waiting out a resend cooldown for nothing.
     */
    public function test_a_conflict_does_not_consume_the_code(): void
    {
        $code = $this->startRegistration($this->payload());

        User::factory()->create(['phone' => self::PHONE]);

        $this->verify($code)->assertStatus(409);

        $this->assertNull(OtpVerification::where('phone', self::PHONE)->sole()->verified_at);
    }
}
