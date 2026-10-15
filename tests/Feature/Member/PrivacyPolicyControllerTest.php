<?php

namespace Tests\Feature\Member;

use App\Domain\Ecosystem\Models\PrivacyPolicy;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The app renders the policy as it comes: one language, Markdown, under data.
 */
class PrivacyPolicyControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);
    }

    public function test_the_policy_comes_in_the_requested_language_alone(): void
    {
        PrivacyPolicy::create(['content' => ['en' => '# Policy', 'ar' => '# السياسة']]);

        $this->withHeader('lang', 'ar')->getJson('/api/v1/member/privacy-policy')
            ->assertOk()
            ->assertExactJson(['data' => ['content' => '# السياسة']]);

        $this->withHeader('lang', 'en')->getJson('/api/v1/member/privacy-policy')
            ->assertJsonPath('data.content', '# Policy');
    }

    public function test_no_policy_yet_is_null_content(): void
    {
        $this->getJson('/api/v1/member/privacy-policy')->assertOk()->assertExactJson(['data' => ['content' => null]]);
    }
}
