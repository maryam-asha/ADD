<?php

namespace Tests\Feature\Member;

use App\Domain\Ecosystem\Models\TermsOfUse;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The app renders the terms as they come: one language, Markdown, under data.
 */
class TermsOfUseControllerTest extends TestCase
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

    public function test_the_terms_come_in_the_requested_language_alone(): void
    {
        TermsOfUse::create(['content' => ['en' => '# Terms', 'ar' => '# الشروط']]);

        $this->withHeader('lang', 'ar')->getJson('/api/v1/member/terms-of-use')
            ->assertOk()
            ->assertExactJson(['data' => ['content' => '# الشروط']]);

        $this->withHeader('lang', 'en')->getJson('/api/v1/member/terms-of-use')
            ->assertJsonPath('data.content', '# Terms');
    }

    public function test_no_terms_yet_is_null_content(): void
    {
        $this->getJson('/api/v1/member/terms-of-use')->assertOk()->assertExactJson(['data' => ['content' => null]]);
    }
}
