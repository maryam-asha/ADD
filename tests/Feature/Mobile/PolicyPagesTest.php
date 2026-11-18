<?php

namespace Tests\Feature\Mobile;

use App\Domain\Ecosystem\Models\PrivacyPolicy;
use App\Domain\Ecosystem\Models\TermsOfUse;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The privacy policy and the terms of use are read before login — sign-up
 * shows them — as Markdown in the request's language alone. Agreeing to the
 * policy is still a member's own act, behind login.
 */
class PolicyPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_privacy_policy_needs_no_login(): void
    {
        PrivacyPolicy::create(['content' => ['en' => '# Policy', 'ar' => '# السياسة']]);

        $this->withHeader('lang', 'ar')->getJson('/api/v1/privacy-policy')
            ->assertOk()
            ->assertExactJson(['data' => ['content' => '# السياسة']]);

        $this->withHeader('lang', 'en')->getJson('/api/v1/privacy-policy')->assertJsonPath('data.content', '# Policy');
    }

    public function test_the_terms_of_use_need_no_login(): void
    {
        TermsOfUse::create(['content' => ['en' => '# Terms', 'ar' => '# الشروط']]);

        $this->withHeader('lang', 'ar')->getJson('/api/v1/terms-of-use')
            ->assertOk()
            ->assertExactJson(['data' => ['content' => '# الشروط']]);

        $this->withHeader('lang', 'en')->getJson('/api/v1/terms-of-use')->assertJsonPath('data.content', '# Terms');
    }

    public function test_nothing_written_yet_is_null_content(): void
    {
        $this->getJson('/api/v1/privacy-policy')->assertOk()->assertExactJson(['data' => ['content' => null]]);
        $this->getJson('/api/v1/terms-of-use')->assertOk()->assertExactJson(['data' => ['content' => null]]);
    }

    public function test_consent_still_needs_a_member(): void
    {
        $policy = PrivacyPolicy::create(['content' => ['en' => 'x', 'ar' => 'x']]);

        $this->postJson('/api/v1/member/privacy-policy/consent')->assertUnauthorized();

        $this->seed(RoleSeeder::class);
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);

        $this->postJson('/api/v1/member/privacy-policy/consent')->assertOk();
        $this->assertDatabaseHas('user_privacy_policy_consents', ['user_id' => $member->id, 'privacy_policy_id' => $policy->id]);
    }
}
