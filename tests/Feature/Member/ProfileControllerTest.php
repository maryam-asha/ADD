<?php

namespace Tests\Feature\Member;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_show_returns_the_combined_shape_with_null_fields_when_nothing_saved_yet(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->getJson('/api/v1/member/profile');

        $response->assertOk();
        $response->assertJsonPath('data.id', $member->id);
        $response->assertJsonPath('data.name', $member->name);
        $response->assertJsonPath('data.phone', $member->phone);
        $response->assertJsonPath('data.email', $member->email);
        $response->assertJsonPath('data.preferred_language', $member->preferred_language);
        $response->assertJsonPath('data.preferred_currency', $member->preferred_currency);
        $response->assertJsonPath('data.status', $member->status);
        $response->assertJsonPath('data.personal.bio', null);
        $response->assertJsonPath('data.personal.city', null);
        $response->assertJsonPath('data.personal.avatar_url', null);
        $response->assertJsonPath('data.professional.job_title', null);
        $response->assertJsonPath('data.professional.company_name', null);
        $response->assertJsonPath('data.professional.industry', null);
        $response->assertJsonPath('data.professional.linkedin_url', null);
        $response->assertJsonMissingPath('data.roles');
    }

    public function test_patch_with_all_seven_fields_creates_both_rows_in_one_call(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->withHeader('lang', 'en')->patchJson('/api/v1/member/profile', [
            'bio' => 'Coffee and code.',
            'city' => 'Aleppo',
            'avatar_url' => 'https://example.com/avatar.png',
            'job_title' => 'Founder',
            'company_name' => 'ACME',
            'industry' => 'Software',
            'linkedin_url' => 'https://linkedin.com/in/example',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Profile updated.');

        $this->assertDatabaseCount('user_personal_profiles', 1);
        $this->assertDatabaseCount('user_professional_profiles', 1);
        $this->assertDatabaseHas('user_personal_profiles', [
            'user_id' => $member->id,
            'city' => 'Aleppo',
        ]);
        $this->assertDatabaseHas('user_professional_profiles', [
            'user_id' => $member->id,
            'company_name' => 'ACME',
        ]);
    }

    public function test_patching_again_upserts_both_rows_without_creating_duplicates(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->patchJson('/api/v1/member/profile', [
            'bio' => 'Coffee and code.',
            'city' => 'Aleppo',
            'job_title' => 'Founder',
            'company_name' => 'ACME',
        ])->assertOk();

        $this->patchJson('/api/v1/member/profile', [
            'bio' => 'Tea now.',
            'city' => 'Damascus',
            'job_title' => 'CEO',
            'company_name' => 'ACME Inc.',
        ])->assertOk();

        $this->assertDatabaseCount('user_personal_profiles', 1);
        $this->assertDatabaseCount('user_professional_profiles', 1);
        $this->assertDatabaseHas('user_personal_profiles', [
            'user_id' => $member->id,
            'city' => 'Damascus',
        ]);
        $this->assertDatabaseHas('user_professional_profiles', [
            'user_id' => $member->id,
            'job_title' => 'CEO',
        ]);
    }

    public function test_patch_with_only_personal_fields_leaves_professional_fields_unset(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->withHeader('lang', 'en')->patchJson('/api/v1/member/profile', [
            'bio' => 'Coffee and code.',
            'city' => 'Aleppo',
            'avatar_url' => 'https://example.com/avatar.png',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Profile updated.');

        $this->assertDatabaseCount('user_personal_profiles', 1);
        $this->assertDatabaseCount('user_professional_profiles', 1);
        $this->assertDatabaseHas('user_professional_profiles', [
            'user_id' => $member->id,
            'job_title' => null,
            'company_name' => null,
        ]);
    }

    public function test_patch_with_a_subset_leaves_the_other_rows_prior_values_intact(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->patchJson('/api/v1/member/profile', [
            'bio' => 'Coffee and code.',
            'city' => 'Aleppo',
            'job_title' => 'Founder',
            'company_name' => 'ACME',
        ])->assertOk();

        $response = $this->withHeader('lang', 'en')->patchJson('/api/v1/member/profile', [
            'bio' => 'Tea now.',
            'city' => 'Damascus',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Profile updated.');

        $this->assertDatabaseCount('user_personal_profiles', 1);
        $this->assertDatabaseCount('user_professional_profiles', 1);
        $this->assertDatabaseHas('user_professional_profiles', [
            'user_id' => $member->id,
            'job_title' => 'Founder',
            'company_name' => 'ACME',
        ]);
    }

    public function test_patch_rejects_invalid_input_with_a_422(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->patchJson('/api/v1/member/profile', [
            'avatar_url' => 'not-a-url',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['avatar_url']);
    }
}
