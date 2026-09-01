<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;

class PasswordResetLocalizationTest extends IdentityTestCase
{
    public function test_forgot_password_response_is_translated(): void
    {
        $member = User::factory()->create(['phone' => '+963912345678']);
        $member->assignRole('member');

        $response = $this->withHeader('lang', 'ar')->postJson('/api/v1/auth/password/forgot', [
            'phone' => '+963912345678',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'تم إرسال رمز إعادة تعيين كلمة المرور.');
    }

    public function test_forgot_password_rejects_unknown_number_in_arabic(): void
    {
        $response = $this->withHeader('lang', 'ar')->postJson('/api/v1/auth/password/forgot', [
            'phone' => '+963999999999',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'هذا الرقم غير مسجّل لدينا.');
    }

    public function test_verify_with_an_invalid_code_is_translated(): void
    {
        $member = User::factory()->create(['phone' => '+963912345678']);
        $member->assignRole('member');

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/auth/password/verify', [
            'phone' => '+963912345678',
            'code' => '000000',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Invalid or expired code.');
    }

    public function test_reset_without_prior_verify_is_translated(): void
    {
        $member = User::factory()->create(['phone' => '+963912345678']);
        $member->assignRole('member');

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/auth/password/reset', [
            'phone' => '+963912345678',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Password reset authorization expired or invalid. Please request a new code.');
    }
}
