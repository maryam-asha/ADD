<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;

class AccountInactiveLocalizationTest extends IdentityTestCase
{
    public function test_a_suspended_accounts_login_rejection_is_translated(): void
    {
        $member = User::factory()->create([
            'phone' => '+963912345678',
            'password' => 'correct-horse',
        ]);
        $member->assignRole('member');
        $member->deactivate('testing');

        $response = $this->withHeader('lang', 'ar')->postJson('/api/v1/auth/login', [
            'phone' => '+963912345678',
            'password' => 'correct-horse',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'هذا الحساب معلّق. الرجاء التواصل مع ADD.');
    }
}
