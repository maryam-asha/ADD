<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Models\OtpVerification;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\Support\InteractsWithOtp;

/**
 * `deactivate()` is voluntary and reversible by the account itself;
 * `block()` is punitive and reversible only by an admin
 * (PATCH admin/users/{user}/status). This is what gives that distinction an
 * actual behavioral difference: self-service deactivate
 * (PATCH member/account/deactivate) and self-service reactivation
 * (auth/account/reactivate + /verify) both only ever touch a `deactivated`
 * account — a `blocked` one gets the identical neutral non-behavior as an
 * `active` account or an unknown phone.
 */
class AccountSelfServiceDeactivationTest extends IdentityTestCase
{
    use InteractsWithOtp;

    private const PHONE = '+963912345678';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeOtpProvider();
    }

    private function member(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge(['phone' => self::PHONE], $overrides));
        $user->assignRole('member');

        return $user;
    }

    public function test_a_member_deactivates_their_own_account_and_a_replayed_token_gets_401_not_403(): void
    {
        $member = $this->member();
        $token = $member->createToken('member-app')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/member/account/deactivate', ['reason' => 'taking a break']);

        $response->assertOk();

        $member->refresh();
        $this->assertSame('deactivated', $member->status);
        $this->assertSame('taking a break', $member->status_reason);

        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/member/wallet/options?category=general')
            ->assertUnauthorized();
    }

    public function test_a_deactivated_member_reactivates_via_otp_and_can_use_the_new_token(): void
    {
        $member = $this->member(['status' => 'deactivated']);

        $code = $this->startAccountReactivation(self::PHONE);
        $this->assertNotNull($code);

        $response = $this->postJson('/api/v1/auth/account/reactivate/verify', [
            'phone' => self::PHONE,
            'code' => $code,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['access_token', 'refresh_token']);

        $member->refresh();
        $this->assertSame('active', $member->status);

        Auth::forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$response->json('access_token'))
            ->getJson('/api/v1/member/wallet/options?category=general')
            ->assertOk();
    }

    public function test_a_blocked_member_gets_the_neutral_response_but_no_code_is_ever_issued(): void
    {
        $this->member(['status' => 'blocked']);

        $response = $this->postJson('/api/v1/auth/account/reactivate', ['phone' => self::PHONE]);

        $response->assertOk();
        $response->assertJson(['message' => __('api.auth.account_reactivation_code_sent')]);

        $this->assertNull($this->otpProvider->lastCodeFor(self::PHONE));
        $this->assertDatabaseCount('otp_verifications', 0);
    }

    public function test_an_active_member_and_an_unknown_phone_both_get_the_same_neutral_response_with_no_code_issued(): void
    {
        $this->member(['status' => 'active']);

        $known = $this->postJson('/api/v1/auth/account/reactivate', ['phone' => self::PHONE]);
        $unknown = $this->postJson('/api/v1/auth/account/reactivate', ['phone' => '+963999999999']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json(), $unknown->json());

        $this->assertDatabaseCount('otp_verifications', 0);
    }

    public function test_verify_for_a_blocked_account_is_rejected_and_status_is_unchanged(): void
    {
        $member = $this->member(['status' => 'blocked']);

        // No request() call ever mints a code for this phone, so simulate the
        // only way a code could exist for it: minted directly against the
        // reactivation purpose.
        OtpVerification::create([
            'phone' => self::PHONE,
            'provider' => 'whatsapp',
            'purpose' => OtpPurpose::AccountReactivation,
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
        ]);

        $response = $this->postJson('/api/v1/auth/account/reactivate/verify', [
            'phone' => self::PHONE,
            'code' => '123456',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => __('api.auth.code_invalid')]);

        $member->refresh();
        $this->assertSame('blocked', $member->status);
    }

    public function test_verify_with_a_wrong_code_is_rejected_and_status_is_unchanged(): void
    {
        $member = $this->member(['status' => 'deactivated']);

        $this->startAccountReactivation(self::PHONE);

        $response = $this->postJson('/api/v1/auth/account/reactivate/verify', [
            'phone' => self::PHONE,
            'code' => '000000',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => __('api.auth.code_invalid')]);

        $member->refresh();
        $this->assertSame('deactivated', $member->status);
    }

    public function test_verify_with_no_prior_request_is_rejected(): void
    {
        $member = $this->member(['status' => 'deactivated']);

        $response = $this->postJson('/api/v1/auth/account/reactivate/verify', [
            'phone' => self::PHONE,
            'code' => '123456',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => __('api.auth.code_invalid')]);

        $member->refresh();
        $this->assertSame('deactivated', $member->status);
    }
}
