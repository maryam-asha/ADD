<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/**
 * Fortify's email reset serves the operations dashboard. This API renders no
 * views (`config/fortify.php`'s `views => false`), so Fortify never registers
 * the `password.reset` GET route that Laravel's default ResetPassword
 * notification links to — left alone, building that link throws
 * RouteNotFoundException and `POST /forgot-password` 500s. The link belongs on
 * the dashboard anyway: its /reset-password page reads `?token=…&email=…` and
 * posts them back to `POST /reset-password` here.
 */
class DashboardPasswordResetLinkTest extends IdentityTestCase
{
    private const EMAIL = 'ops@example.com';

    private const NEW_PASSWORD = 'Brand-New-Password-1';

    private function operations(): User
    {
        $user = User::factory()->create(['email' => self::EMAIL]);

        $user->assignRole('operations');

        return $user;
    }

    private function forgot(): TestResponse
    {
        return $this->postJson('/forgot-password', ['email' => self::EMAIL]);
    }

    public function test_forgot_password_mails_a_link_without_a_password_reset_route(): void
    {
        $this->operations();

        $this->forgot()->assertOk();
    }

    public function test_reset_link_points_at_the_dashboard_reset_page(): void
    {
        config(['app.frontend_url' => 'https://dashboard.example.test']);

        Notification::fake();

        $user = $this->operations();

        $this->forgot()->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, 'https://dashboard.example.test/reset-password?')
                && str_contains($url, 'token='.$notification->token)
                && str_contains($url, 'email='.urlencode($user->email));
        });
    }

    public function test_the_mailed_token_completes_a_reset(): void
    {
        Notification::fake();

        $user = $this->operations();

        $this->forgot()->assertOk();

        $token = null;

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => self::EMAIL,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }
}
