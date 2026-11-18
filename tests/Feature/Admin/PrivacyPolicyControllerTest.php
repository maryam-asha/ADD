<?php

namespace Tests\Feature\Admin;

use App\Domain\Ecosystem\Models\PrivacyPolicy;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrivacyPolicyControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
    }

    /** The first edit, with no policy row yet, used to answer 200 and store nothing. */
    public function test_the_first_edit_creates_the_policy(): void
    {
        $this->patchJson('/api/v1/admin/privacy-policy', ['content' => ['en' => 'Policy', 'ar' => 'السياسة']])->assertOk();

        $this->assertSame(['en' => 'Policy', 'ar' => 'السياسة'], PrivacyPolicy::sole()->content);
    }

    /** The dashboard edits both languages, so it reads both, under data. */
    public function test_the_dashboard_reads_both_languages_under_data(): void
    {
        $policy = PrivacyPolicy::create(['content' => ['en' => 'Policy', 'ar' => 'السياسة']]);

        $this->getJson('/api/v1/admin/privacy-policy')
            ->assertOk()
            ->assertJsonPath('data.id', $policy->id)
            ->assertJsonPath('data.content', ['en' => 'Policy', 'ar' => 'السياسة']);
    }

    public function test_a_later_edit_updates_the_same_policy(): void
    {
        PrivacyPolicy::create(['content' => ['en' => 'Old', 'ar' => 'قديم']]);

        $this->patchJson('/api/v1/admin/privacy-policy', ['content' => ['en' => 'New', 'ar' => 'جديد']])->assertOk();

        $this->assertSame(['en' => 'New', 'ar' => 'جديد'], PrivacyPolicy::sole()->content);
    }
}
