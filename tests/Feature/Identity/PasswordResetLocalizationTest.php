<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;

class PasswordResetLocalizationTest extends IdentityTestCase
{
    public function test_forgot_password_response_is_translated(): void
    {
        $member = User::factory()->create(['phone' => '0912345678']);
        $member->assignRole('member');

        $response = $this->withHeader('lang', 'ar')->postJson('/api/v1/auth/password/forgot', [
            'phone' => '0912345678',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'إذا كان هذا الرقم مرتبطاً بحساب، فسيتم إرسال رمز إعادة التعيين إليه.');
    }

    public function test_reset_with_an_invalid_code_is_translated(): void
    {
        $member = User::factory()->create(['phone' => '0912345678']);
        $member->assignRole('member');

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/auth/password/reset', [
            'phone' => '0912345678',
            'code' => '000000',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Invalid or expired code.');
    }
}
