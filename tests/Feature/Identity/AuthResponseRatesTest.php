<?php

namespace Tests\Feature\Identity;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use App\Services\Auth\TokenPairService;
use Tests\Support\InteractsWithOtp;

/**
 * Every response that opens a member session carries, inside `user`, the
 * rates the app keeps for the session: SYP per one USD (the reciprocal of the
 * stored rate_to_base) and the point's USD value from settings.
 */
class AuthResponseRatesTest extends IdentityTestCase
{
    use InteractsWithOtp;

    private const PHONE = '+963912345678';

    private const PASSWORD = 'correct-horse';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeOtpProvider();

        // 1 USD = 140 SYP, stored as its 10-decimal reciprocal.
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0071428571']);
        app(SettingService::class)->set(PointConversionService::SETTING_KEY, '0.10', SettingValueType::String);
    }

    private function member(): User
    {
        $user = User::factory()->create(['phone' => self::PHONE, 'password' => self::PASSWORD]);
        $user->assignRole('member');

        return $user;
    }

    public function test_login_returns_the_rates_inside_the_user(): void
    {
        $this->member();

        $this->postJson('/api/v1/auth/login', ['phone' => self::PHONE, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.rates.usd_to_syp', 140)
            ->assertJsonPath('user.rates.point_rate_usd', 0.1);
    }

    public function test_registration_returns_the_rates_inside_the_user(): void
    {
        $this->startRegistration([
            'phone' => self::PHONE,
            'name' => 'Maryam Asha',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);

        $this->postJson('/api/v1/auth/register/verify', [
            'phone' => self::PHONE,
            'code' => $this->otpProvider->lastCodeFor(self::PHONE),
        ])
            ->assertOk()
            ->assertJsonPath('user.rates.usd_to_syp', 140)
            ->assertJsonPath('user.rates.point_rate_usd', 0.1);
    }

    public function test_refresh_returns_the_user_with_the_current_rates(): void
    {
        $pair = app(TokenPairService::class)->issue($this->member());

        // Changed mid-session: the refresh must carry the new figures.
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000769231', 'effective_from' => now()]);
        app(SettingService::class)->set(PointConversionService::SETTING_KEY, '0.0125');

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair->refreshToken])
            ->assertOk()
            ->assertJsonPath('user.phone', self::PHONE)
            ->assertJsonPath('user.rates.usd_to_syp', 13000)
            ->assertJsonPath('user.rates.point_rate_usd', 0.0125);
    }

    public function test_the_syp_rate_is_null_when_none_is_set(): void
    {
        ExchangeRate::query()->delete();
        $this->member();

        $this->postJson('/api/v1/auth/login', ['phone' => self::PHONE, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.rates.usd_to_syp', null)
            ->assertJsonPath('user.rates.point_rate_usd', 0.1);
    }

    public function test_me_is_unchanged(): void
    {
        $this->actingAs($this->member())
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonMissingPath('user.rates');
    }
}
