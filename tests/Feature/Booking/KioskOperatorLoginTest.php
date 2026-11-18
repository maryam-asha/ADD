<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\Kiosk;
use App\Domain\Booking\Services\KioskPairingService;
use App\Domain\Identity\Models\User;
use App\Services\Auth\TokenPairService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The kiosk app's setup screens: a staff login first, and the pairing code only
 * behind it. The token proves one thing, that someone allowed to pair is standing
 * at this device, so it pairs and does nothing else.
 */
class KioskOperatorLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function operator(array $attributes = []): User
    {
        $user = User::factory()->create(['password' => self::PASSWORD, ...$attributes]);
        $user->assignRole('admin');

        return $user;
    }

    private function login(string $email, string $password = self::PASSWORD, array $extra = []): TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/kiosk/auth/login', [
            'email' => $email,
            'password' => $password,
            ...$extra,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $this->login($user->email)->assertOk()->json('data.access_token');
    }

    /** A request as a fresh one reaches the app: no guard remembering the last caller. */
    private function pairWith(?string $token, string $code): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        if ($token !== null) {
            $this->withToken($token);
        }

        return $this->withHeader('lang', 'en')->postJson('/api/v1/kiosk/pair', ['pairing_code' => $code]);
    }

    private function codeFor(Kiosk $kiosk): string
    {
        return app(KioskPairingService::class)->issueCode($kiosk)->code;
    }

    private function withTwoFactor(User $user): string
    {
        $secret = app(Google2FA::class)->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $secret;
    }

    public function test_someone_allowed_to_pair_gets_a_fifteen_minute_token_that_only_pairs(): void
    {
        $this->freezeSecond();
        $user = $this->operator();

        $response = $this->login($user->email)->assertOk();

        $token = PersonalAccessToken::findToken($response->json('data.access_token'));
        $this->assertTrue($token->tokenable->is($user));
        $this->assertSame(['kiosk-pair'], $token->abilities);
        $this->assertTrue($token->expires_at->equalTo(now()->addMinutes(15)));
        $this->assertSame($token->expires_at->toIso8601String(), $response->json('data.expires_at'));
    }

    public function test_the_permission_is_what_lets_someone_in_not_the_role(): void
    {
        Role::findOrCreate('kiosk-technician', 'web')->givePermissionTo('kiosks.pair');
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $user->assignRole('kiosk-technician');

        $this->login($user->email)->assertOk();
    }

    public function test_every_refused_login_gets_the_same_answer(): void
    {
        $expected = ['message' => 'These credentials do not match our records.'];

        $operator = $this->operator();
        $this->login($operator->email, 'wrong-password')->assertUnauthorized()->assertExactJson($expected);
        $this->login('nobody@example.test')->assertUnauthorized()->assertExactJson($expected);

        $member = User::factory()->create(['password' => self::PASSWORD]);
        $member->assignRole('member');
        $this->login($member->email)->assertUnauthorized()->assertExactJson($expected);

        $operations = User::factory()->create(['password' => self::PASSWORD]);
        $operations->assignRole('operations');
        $this->login($operations->email)->assertUnauthorized()->assertExactJson($expected);

        $blocked = $this->operator(['status' => 'blocked']);
        $this->login($blocked->email)->assertUnauthorized()->assertExactJson($expected);

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_an_email_typed_in_capitals_finds_the_account(): void
    {
        $user = $this->operator(['email' => 'tech@example.test']);

        $this->login('Tech@Example.TEST')->assertOk();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_an_account_with_two_factor_is_asked_for_its_code(): void
    {
        $user = $this->operator();
        $this->withTwoFactor($user);

        $this->login($user->email)
            ->assertStatus(422)
            ->assertJsonPath('errors.two_factor_code.0', 'Enter the code from your authenticator app.');

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_a_wrong_two_factor_code_mints_nothing(): void
    {
        $user = $this->operator();
        $this->withTwoFactor($user);

        $this->login($user->email, extra: ['two_factor_code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('errors.two_factor_code.0', 'This authenticator code is not valid.');

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_the_right_two_factor_code_lets_them_in(): void
    {
        $user = $this->operator();
        $secret = $this->withTwoFactor($user);

        $this->login($user->email, extra: ['two_factor_code' => app(Google2FA::class)->getCurrentOtp($secret)])
            ->assertOk();
    }

    public function test_someone_without_the_permission_never_reaches_the_two_factor_question(): void
    {
        $member = User::factory()->create(['password' => self::PASSWORD]);
        $member->assignRole('member');
        $this->withTwoFactor($member);

        $this->login($member->email)->assertUnauthorized();
    }

    public function test_a_new_login_replaces_the_previous_pairing_token_only(): void
    {
        $user = $this->operator();
        $first = $this->tokenFor($user);
        $other = $user->createToken('dashboard-tool', ['dashboard']);

        $this->tokenFor($user);

        $this->assertNull(PersonalAccessToken::findToken($first));
        $this->assertNotNull(PersonalAccessToken::findToken($other->plainTextToken));
    }

    public function test_the_login_is_throttled(): void
    {
        $user = $this->operator();

        foreach (range(1, 5) as $attempt) {
            $this->login($user->email, 'wrong-password')->assertUnauthorized();
        }

        $this->login($user->email, 'wrong-password')->assertTooManyRequests();
    }

    public function test_pairing_with_the_login_token_delivers_the_secret_and_ends_the_token(): void
    {
        $user = $this->operator();
        $kiosk = Kiosk::factory()->create();
        $token = $this->tokenFor($user);

        $this->pairWith($token, $this->codeFor($kiosk))
            ->assertOk()
            ->assertJsonPath('data.kiosk_id', $kiosk->id);

        $this->assertNotNull($kiosk->fresh()->totp_secret);
        $this->assertNull(PersonalAccessToken::findToken($token));
    }

    public function test_a_mistyped_code_keeps_the_token_so_the_operator_can_retype_it(): void
    {
        $kiosk = Kiosk::factory()->create();
        $token = $this->tokenFor($this->operator());

        $this->pairWith($token, 'WRONGONE')->assertStatus(422);

        $this->pairWith($token, $this->codeFor($kiosk))->assertOk();
    }

    public function test_a_valid_code_alone_no_longer_pairs(): void
    {
        $kiosk = Kiosk::factory()->create();

        $this->pairWith(null, $this->codeFor($kiosk))->assertUnauthorized();

        $this->assertNull($kiosk->fresh()->totp_secret);
    }

    public function test_a_member_app_token_cannot_pair_even_for_someone_holding_the_permission(): void
    {
        $user = $this->operator();
        $kiosk = Kiosk::factory()->create();
        $token = $user->createToken('member-app', [TokenPairService::MEMBER_APP_ABILITY])->plainTextToken;

        $this->pairWith($token, $this->codeFor($kiosk))->assertForbidden();

        $this->assertNull($kiosk->fresh()->totp_secret);
    }

    public function test_an_expired_login_token_cannot_pair(): void
    {
        $kiosk = Kiosk::factory()->create();
        $token = $this->tokenFor($this->operator());

        $this->travel(16)->minutes();

        $this->pairWith($token, $this->codeFor($kiosk))->assertUnauthorized();
    }

    public function test_losing_the_permission_after_login_stops_the_pairing(): void
    {
        $user = $this->operator();
        $kiosk = Kiosk::factory()->create();
        $token = $this->tokenFor($user);

        $user->syncRoles(['member']);

        $this->pairWith($token, $this->codeFor($kiosk))->assertForbidden();

        $this->assertNull($kiosk->fresh()->totp_secret);
    }

    public function test_the_login_token_does_not_open_the_admin_api(): void
    {
        $token = $this->tokenFor($this->operator());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/kiosks')->assertForbidden();
    }
}
