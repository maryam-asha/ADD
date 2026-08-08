<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use App\Services\Otp\OtpProvider;
use stdClass;

/**
 * Regression check after the staff -> operations rename
 * (docs/decisions/staff-operations-rename.md): the rename touched
 * RoleSeeder and two Form Requests but must not have touched what role a
 * newly-verified member gets.
 */
class MemberOtpLoginTest extends IdentityTestCase
{
    public function test_verifying_a_valid_code_creates_a_member_and_assigns_the_member_role(): void
    {
        $captured = new stdClass;

        $this->app->bind(OtpProvider::class, fn () => new class($captured) implements OtpProvider
        {
            public function __construct(private stdClass $captured) {}

            public function send(string $phone, string $code, string $provider): bool
            {
                $this->captured->code = $code;

                return true;
            }
        });

        $phone = '0912345678';

        $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone])->assertOk();

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $phone,
            'code' => $captured->code,
        ]);

        $response->assertOk();
        $response->assertJsonPath('user.phone', $phone);

        $user = User::where('phone', $phone)->first();
        $this->assertTrue($user->hasRole('member'));
        $this->assertFalse($user->hasRole('operations'));
    }
}
