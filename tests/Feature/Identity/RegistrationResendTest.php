<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\PendingRegistration;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\InteractsWithOtp;
use Tests\TestCase;

/**
 * register/resend answers "sent" only when a code actually went out — never
 * as a blanket reply for a number with nothing to resend.
 */
class RegistrationResendTest extends TestCase
{
    use InteractsWithOtp;
    use RefreshDatabase;

    private const PHONE = '+963912345678';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeOtpProvider();
    }

    private function resend(): TestResponse
    {
        return $this->postJson('/api/v1/auth/register/resend', ['phone' => self::PHONE]);
    }

    private function park(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'phone' => self::PHONE,
            'name' => 'Maryam Asha',
            'email' => 'maryam@example.com',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
        ])->assertOk();

        $this->travel(61)->seconds();
    }

    public function test_a_parked_registration_gets_a_fresh_code(): void
    {
        $this->park();
        $first = $this->otpProvider->lastCodeFor(self::PHONE);

        $this->resend()->assertOk()->assertJson(['message' => __('api.auth.otp_sent')]);

        $this->assertCount(2, $this->otpProvider->sent);
        $this->assertNotNull($first);
    }

    public function test_a_number_that_already_has_an_account_is_told_so(): void
    {
        User::factory()->create(['phone' => self::PHONE]);

        $this->resend()
            ->assertStatus(422)
            ->assertJson(['message' => __('api.auth.phone_already_registered')]);

        $this->assertSame([], $this->otpProvider->sent);
    }

    public function test_a_number_with_nothing_parked_is_told_to_start_again(): void
    {
        $this->resend()
            ->assertStatus(422)
            ->assertJson(['message' => __('api.auth.registration_code_invalid')]);

        $this->assertSame([], $this->otpProvider->sent);
    }

    public function test_an_expired_parked_registration_is_told_to_start_again(): void
    {
        $this->park();
        PendingRegistration::query()->update(['expires_at' => now()->subMinute()]);

        $this->resend()->assertStatus(422);

        $this->assertCount(1, $this->otpProvider->sent);
    }
}
