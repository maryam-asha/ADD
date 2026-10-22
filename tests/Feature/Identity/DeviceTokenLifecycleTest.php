<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * A device's FCM token lives and dies with the session it was registered on:
 * sent at login, carried across a refresh, gone at logout or whenever every
 * session is cut — and never shared between two accounts on one phone.
 */
class DeviceTokenLifecycleTest extends IdentityTestCase
{
    private const PASSWORD = 'correct-horse';

    private function member(string $phone = '+963912345678'): User
    {
        $user = User::factory()->create(['phone' => $phone, 'password' => self::PASSWORD, 'fcm_token' => null]);
        $user->assignRole('member');

        return $user;
    }

    private function login(User $user, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'phone' => $user->phone,
            'password' => self::PASSWORD,
            ...$extra,
        ])->assertOk();
    }

    private function asBearer(string $accessToken): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$accessToken);
    }

    public function test_login_with_an_fcm_token_registers_the_device_on_that_session(): void
    {
        $member = $this->member();

        $this->login($member, ['fcm_token' => 'fcm-phone', 'platform' => 'android']);

        $device = $member->deviceTokens()->sole();
        $this->assertSame('fcm-phone', $device->token);
        $this->assertSame('android', $device->platform);
        $this->assertSame($member->tokens()->sole()->id, $device->access_token_id);
        $this->assertSame('fcm-phone', $member->fresh()->fcm_token);
    }

    public function test_login_without_an_fcm_token_still_works_and_registers_nothing(): void
    {
        $member = $this->member();

        $this->login($member);

        $this->assertSame(0, $member->deviceTokens()->count());
    }

    public function test_the_platform_must_be_a_known_one(): void
    {
        $member = $this->member();

        $this->postJson('/api/v1/auth/login', [
            'phone' => $member->phone,
            'password' => self::PASSWORD,
            'fcm_token' => 'fcm-phone',
            'platform' => 'symbian',
        ])->assertJsonValidationErrors('platform');
    }

    public function test_a_refresh_carries_the_device_over_to_the_new_session(): void
    {
        $member = $this->member();
        $pair = $this->login($member, ['fcm_token' => 'fcm-phone']);

        $refreshed = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair->json('refresh_token')])->assertOk();

        $this->assertSame($member->tokens()->sole()->id, $member->deviceTokens()->sole()->access_token_id);

        $this->asBearer($refreshed->json('access_token'))->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, $member->deviceTokens()->count());
    }

    public function test_a_refresh_with_a_new_fcm_token_registers_it(): void
    {
        $member = $this->member();
        $pair = $this->login($member, ['fcm_token' => 'fcm-old']);

        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $pair->json('refresh_token'),
            'fcm_token' => 'fcm-new',
        ])->assertOk();

        $this->assertEqualsCanonicalizing(['fcm-old', 'fcm-new'], $member->deviceTokens()->pluck('token')->all());
        $this->assertSame('fcm-new', $member->fresh()->fcm_token);
    }

    public function test_logout_stops_pushes_to_that_device_only(): void
    {
        $member = $this->member();
        $phone = $this->login($member, ['fcm_token' => 'fcm-phone']);
        $this->login($member, ['fcm_token' => 'fcm-tablet']);

        $this->asBearer($phone->json('access_token'))->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertSame(['fcm-tablet'], $member->deviceTokens()->pluck('token')->all());
        $this->assertSame('fcm-tablet', $member->fresh()->fcm_token);
    }

    public function test_logout_removes_a_token_sent_in_the_body_even_when_not_tied_to_the_session(): void
    {
        $member = $this->member();
        $member->deviceTokens()->create(['token' => 'fcm-legacy']);
        $member->forceFill(['fcm_token' => 'fcm-legacy'])->save();
        $pair = $this->login($member);

        $this->asBearer($pair->json('access_token'))->postJson('/api/v1/auth/logout', ['fcm_token' => 'fcm-legacy'])->assertOk();

        $this->assertSame(0, $member->deviceTokens()->count());
        $this->assertNull($member->fresh()->fcm_token);
    }

    public function test_a_phone_signing_into_another_account_stops_the_first_accounts_pushes(): void
    {
        $first = $this->member('+963911111111');
        $second = $this->member('+963922222222');
        $this->login($first, ['fcm_token' => 'fcm-shared-phone']);

        $this->login($second, ['fcm_token' => 'fcm-shared-phone']);

        $this->assertSame(0, $first->deviceTokens()->count());
        $this->assertNull($first->fresh()->fcm_token);
        $this->assertSame(['fcm-shared-phone'], $second->deviceTokens()->pluck('token')->all());
    }

    public function test_login_stores_what_the_app_reports_about_the_device(): void
    {
        $member = $this->member();

        $this->login($member, [
            'fcm_token' => 'fcm-phone',
            'platform' => 'android',
            'device_id' => 'install-1',
            'app_version' => '1.4.2',
            'build_number' => '142',
            'os_version' => '14',
            'device_model' => 'SM-S918B',
        ]);

        $device = $member->deviceTokens()->sole();
        $this->assertSame(
            ['install-1', '1.4.2', '142', '14', 'SM-S918B'],
            [$device->device_id, $device->app_version, $device->build_number, $device->os_version, $device->device_model],
        );
    }

    public function test_a_rotated_token_from_the_same_install_replaces_the_old_one(): void
    {
        $member = $this->member();
        $pair = $this->login($member, ['fcm_token' => 'fcm-old', 'device_id' => 'install-1', 'app_version' => '1.4.2']);

        $this->asBearer($pair->json('access_token'))
            ->putJson('/api/v1/member/device-token', ['fcm_token' => 'fcm-new', 'device_id' => 'install-1', 'app_version' => '1.5.0'])
            ->assertOk();

        $device = $member->deviceTokens()->sole();
        $this->assertSame('fcm-new', $device->token);
        $this->assertSame('1.5.0', $device->app_version);
        $this->assertSame('fcm-new', $member->fresh()->fcm_token);
    }

    public function test_an_install_signing_into_another_account_leaves_the_first_one(): void
    {
        $first = $this->member('+963911111111');
        $second = $this->member('+963922222222');
        $this->login($first, ['fcm_token' => 'fcm-old', 'device_id' => 'install-1']);

        $this->login($second, ['fcm_token' => 'fcm-new', 'device_id' => 'install-1']);

        $this->assertSame(0, $first->deviceTokens()->count());
        $this->assertSame(['fcm-new'], $second->deviceTokens()->pluck('token')->all());
    }

    public function test_put_device_token_ties_the_token_to_the_calling_session(): void
    {
        $member = $this->member();
        $pair = $this->login($member);

        $this->asBearer($pair->json('access_token'))
            ->putJson('/api/v1/member/device-token', ['fcm_token' => 'fcm-phone', 'platform' => 'ios'])
            ->assertOk();

        $this->assertSame($member->tokens()->sole()->id, $member->deviceTokens()->sole()->access_token_id);
    }

    public function test_changing_the_password_keeps_only_the_current_devices_token(): void
    {
        $member = $this->member();
        $phone = $this->login($member, ['fcm_token' => 'fcm-phone']);
        $this->login($member, ['fcm_token' => 'fcm-tablet']);

        $this->asBearer($phone->json('access_token'))->patchJson('/api/v1/member/account/password', [
            'current_password' => self::PASSWORD,
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertOk();

        $this->assertSame(['fcm-phone'], $member->deviceTokens()->pluck('token')->all());
        $this->assertSame('fcm-phone', $member->fresh()->fcm_token);
    }

    public function test_blocking_an_account_forgets_every_device(): void
    {
        $member = $this->member();
        $this->login($member, ['fcm_token' => 'fcm-phone']);

        $member->fresh()->block('test');

        $this->assertSame(0, $member->deviceTokens()->count());
        $this->assertNull($member->fresh()->fcm_token);
    }

    public function test_deleting_the_account_forgets_every_device(): void
    {
        $member = $this->member();
        $pair = $this->login($member, ['fcm_token' => 'fcm-phone']);

        $this->asBearer($pair->json('access_token'))->deleteJson('/api/v1/member/account')->assertOk();

        $this->assertSame(0, $member->deviceTokens()->count());
    }
}
