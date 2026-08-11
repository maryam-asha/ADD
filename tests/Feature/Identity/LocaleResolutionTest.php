<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;

class LocaleResolutionTest extends IdentityTestCase
{
    private function member(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'phone' => '0912345678',
            'password' => 'correct-horse',
        ], $overrides));

        $user->assignRole('member');

        return $user;
    }

    public function test_a_valid_header_is_read_on_an_unauthenticated_request(): void
    {
        $response = $this->withHeader('lang', 'ar')->postJson('/api/v1/auth/login', [
            'phone' => '0000000000',
            'password' => 'anything',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'بيانات الدخول غير مطابقة لسجلاتنا.');
    }

    public function test_no_header_falls_back_to_the_authenticated_users_preferred_language(): void
    {
        $member = $this->member(['preferred_language' => 'en']);

        // A real token, not Sanctum::actingAs() — actingAs() sets the guard's
        // user directly and never dispatches TokenAuthenticated (confirmed by
        // this codebase's own SuspendedAccountAccessTest docblock), which is
        // exactly the event SetLocaleFromUserPreference listens for.
        $token = $member->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $response->assertOk();
        $this->assertSame('en', app()->getLocale());
    }

    public function test_a_valid_header_overrides_the_authenticated_users_preferred_language(): void
    {
        $member = $this->member(['preferred_language' => 'ar']);

        $token = $member->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('lang', 'en')
            ->getJson('/api/v1/auth/me');

        $response->assertOk();
        $this->assertSame('en', app()->getLocale());
    }
}
