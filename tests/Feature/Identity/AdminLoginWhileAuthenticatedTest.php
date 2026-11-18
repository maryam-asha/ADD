<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Regression test for the AddDashboard SPA login bug: a browser that already
 * holds a valid Fortify session cookie and re-submits `/login` (stale tab,
 * accidental double-submit, reload of the login screen) must get a JSON
 * response, not Laravel's stock `RedirectIfAuthenticated` redirect. That
 * redirect resolves to `/` (this app defines no `home`/`dashboard` route),
 * which `config/cors.php` never allow-lists — so the SPA's `fetch()` (which
 * follows redirects automatically) saw an opaque CORS error instead of ever
 * learning it was already logged in. See app/Http/Middleware/
 * RedirectIfAuthenticated.php, aliased over the 'guest' middleware in
 * bootstrap/app.php.
 */
class AdminLoginWhileAuthenticatedTest extends IdentityTestCase
{
    public function test_logging_in_again_while_already_authenticated_returns_json_not_a_redirect(): void
    {
        $password = 'correct-password';
        $operator = User::factory()->create(['password' => Hash::make($password)]);
        $operator->assignRole('operations');

        $this->postJson('/login', [
            'email' => $operator->email,
            'password' => $password,
        ])->assertOk();

        $response = $this->postJson('/login', [
            'email' => $operator->email,
            'password' => $password,
        ]);

        $response->assertStatus(409);
        $response->assertJsonStructure(['message']);
    }
}
