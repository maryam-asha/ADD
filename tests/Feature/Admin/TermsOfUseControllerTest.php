<?php

namespace Tests\Feature\Admin;

use App\Domain\Ecosystem\Models\TermsOfUse;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TermsOfUseControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('operations');
        Sanctum::actingAs($admin, ['*']);
    }

    public function test_the_first_edit_creates_the_terms(): void
    {
        $this->patchJson('/api/v1/admin/terms-of-use', ['content' => ['en' => '# Terms', 'ar' => '# الشروط']])->assertOk();

        $this->assertSame(['en' => '# Terms', 'ar' => '# الشروط'], TermsOfUse::sole()->content);
    }

    public function test_a_later_edit_updates_the_same_terms(): void
    {
        TermsOfUse::create(['content' => ['en' => 'Old', 'ar' => 'قديم']]);

        $this->patchJson('/api/v1/admin/terms-of-use', ['content' => ['en' => 'New', 'ar' => 'جديد']])->assertOk();

        $this->assertSame(['en' => 'New', 'ar' => 'جديد'], TermsOfUse::sole()->content);
    }

    public function test_both_languages_are_required(): void
    {
        $this->patchJson('/api/v1/admin/terms-of-use', ['content' => ['en' => 'Only English']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content.ar');
    }

    /** The dashboard edits both languages, so it reads both, under data. */
    public function test_the_dashboard_reads_both_languages_under_data(): void
    {
        $terms = TermsOfUse::create(['content' => ['en' => 'Terms', 'ar' => 'الشروط']]);

        $this->getJson('/api/v1/admin/terms-of-use')
            ->assertOk()
            ->assertJsonPath('data.id', $terms->id)
            ->assertJsonPath('data.content', ['en' => 'Terms', 'ar' => 'الشروط']);
    }

    public function test_a_member_cannot_edit_the_terms(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);

        $this->patchJson('/api/v1/admin/terms-of-use', ['content' => ['en' => 'x', 'ar' => 'x']])->assertForbidden();
        $this->assertSame(0, TermsOfUse::count());
    }
}
