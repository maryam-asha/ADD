<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * The six scenarios the original localization request calls out by name:
 * (a) lang=ar on wrong credentials, (b) lang=en on the same, (c) no header
 * for an authenticated member with a stored preference, (d) header
 * overriding that stored preference, (e) an invalid header value falling
 * back rather than failing the request, (f) a translated 429.
 */
class ApiLocalizationTest extends IdentityTestCase
{
    private const PHONE = '0912345678';

    private const PASSWORD = 'correct-horse';

    private function member(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'phone' => self::PHONE,
            'password' => self::PASSWORD,
        ], $overrides));

        $user->assignRole('member');

        return $user;
    }

    private function loginWithWrongPassword(?string $lang = null): TestResponse
    {
        $request = $lang ? $this->withHeader('lang', $lang) : $this;

        return $request->postJson('/api/v1/auth/login', [
            'phone' => self::PHONE,
            'password' => 'not-the-password',
        ]);
    }

    public function test_lang_ar_header_returns_the_arabic_message_for_wrong_credentials(): void
    {
        $this->member();

        $response = $this->loginWithWrongPassword('ar');

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'بيانات الدخول غير مطابقة لسجلاتنا.');
    }

    public function test_lang_en_header_returns_the_english_message_for_wrong_credentials(): void
    {
        $this->member();

        $response = $this->loginWithWrongPassword('en');

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'These credentials do not match our records.');
    }

    public function test_no_header_falls_back_to_the_authenticated_members_stored_preference(): void
    {
        $member = $this->member(['preferred_language' => 'en']);

        // A real token, not Sanctum::actingAs() — actingAs() sets the guard's
        // user directly and never dispatches TokenAuthenticated, which is
        // exactly the event SetLocaleFromUserPreference (Task 3) listens for.
        // Using actingAs() here would make this scenario pass vacuously,
        // without the listener ever running.
        $token = $member->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/member/preferences/language', [
                'preferred_language' => 'fr',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'The given data is invalid.');
    }

    public function test_header_overrides_the_stored_preference(): void
    {
        $member = $this->member(['preferred_language' => 'ar']);

        $token = $member->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('lang', 'en')
            ->patchJson('/api/v1/member/preferences/language', [
                'preferred_language' => 'fr',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'The given data is invalid.');
    }

    public function test_an_unsupported_header_value_falls_back_to_arabic_and_does_not_fail_the_request(): void
    {
        $this->member();

        $response = $this->loginWithWrongPassword('fr');

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'بيانات الدخول غير مطابقة لسجلاتنا.');
    }

    public function test_the_login_throttle_response_is_translated(): void
    {
        $this->member();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->loginWithWrongPassword('en');
        }

        $response = $this->loginWithWrongPassword('en');

        $response->assertStatus(429);
        $response->assertJsonPath('message', 'Too many attempts. Please wait before trying again.');
    }
}
