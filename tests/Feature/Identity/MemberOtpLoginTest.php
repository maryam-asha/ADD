<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Tests\Support\InteractsWithOtp;

/**
 * Regression check after the staff -> operations rename
 * (docs/decisions/staff-operations-rename.md): the rename touched
 * RoleSeeder and two Form Requests but must not have touched what role a
 * newly-registered member gets.
 *
 * The endpoint under test is registration now rather than login — the role
 * assignment it guards moved with it, so the check follows rather than
 * being retired.
 */
class MemberOtpLoginTest extends IdentityTestCase
{
    use InteractsWithOtp;

    public function test_registering_with_a_valid_code_creates_a_member_and_assigns_the_member_role(): void
    {
        $this->fakeOtpProvider();

        $payload = [
            'phone' => '0912345678',
            'name' => 'Maryam Asha',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
        ];

        $code = $this->startRegistration($payload);

        $response = $this->postJson('/api/v1/auth/register/verify', $payload + ['code' => $code]);

        $response->assertOk();
        $response->assertJsonPath('user.phone', '0912345678');

        $user = User::where('phone', '0912345678')->first();
        $this->assertTrue($user->hasRole('member'));
        $this->assertFalse($user->hasRole('operations'));
    }
}
