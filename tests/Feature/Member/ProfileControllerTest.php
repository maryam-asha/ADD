<?php

namespace Tests\Feature\Member;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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
        $response->assertJsonPath('data.user.id', $member->id);
        $response->assertJsonPath('data.user.name', $member->name);
        $response->assertJsonPath('data.user.phone', $member->phone);
        $response->assertJsonPath('data.user.email', $member->email);
        $response->assertJsonPath('data.user.preferred_language', $member->preferred_language);
        $response->assertJsonPath('data.user.preferred_currency', $member->preferred_currency);
        $response->assertJsonPath('data.user.status', $member->status);
        $response->assertJsonPath('data.user.personal.bio', null);
        $response->assertJsonPath('data.user.personal.city', null);
        $response->assertJsonPath('data.user.personal.avatar_url', null);
        $response->assertJsonPath('data.user.professional.job_title', null);
        $response->assertJsonPath('data.user.professional.company_name', null);
        $response->assertJsonPath('data.user.professional.industry', null);
        $response->assertJsonPath('data.user.links.linkedin_url', null);
        $response->assertJsonMissingPath('data.user.roles');
        $response->assertJsonMissingPath('data.user.permissions');
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

    public function test_show_includes_gender_and_the_new_social_links_as_null_by_default(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->getJson('/api/v1/member/profile');

        $response->assertOk();
        $response->assertJsonPath('data.user.personal.gender', null);
        $response->assertJsonPath('data.user.links.instagram_url', null);
        $response->assertJsonPath('data.user.links.behance_url', null);
        $response->assertJsonPath('data.user.links.website_url', null);
    }

    public function test_patch_can_set_gender_and_the_new_social_links(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->withHeader('lang', 'en')->patchJson('/api/v1/member/profile', [
            'gender' => 'female',
            'instagram_url' => 'https://instagram.com/example',
            'behance_url' => 'https://behance.net/example',
            'website_url' => 'https://example.com',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Profile updated.');

        $this->assertDatabaseHas('user_personal_profiles', [
            'user_id' => $member->id,
            'gender' => 'female',
        ]);
        $this->assertDatabaseHas('user_professional_profiles', [
            'user_id' => $member->id,
            'instagram_url' => 'https://instagram.com/example',
            'behance_url' => 'https://behance.net/example',
            'website_url' => 'https://example.com',
        ]);

        $getResponse = $this->getJson('/api/v1/member/profile');
        $getResponse->assertJsonPath('data.user.personal.gender', 'female');
    }

    public function test_patch_rejects_an_invalid_gender_value(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->patchJson('/api/v1/member/profile', [
            'gender' => 'not-a-real-value',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['gender']);
    }

    private function showAsMember(User $member): TestResponse
    {
        Sanctum::actingAs($member, ['*']);

        return $this->getJson('/api/v1/member/profile')->assertOk();
    }

    private function member(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');

        return $member;
    }

    public function test_has_active_membership_is_false_without_a_plan(): void
    {
        $this->showAsMember($this->member())->assertJsonPath('data.has_active_membership', false);
    }

    public function test_has_active_membership_is_true_with_a_live_personal_plan(): void
    {
        $member = $this->member();
        Membership::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $this->showAsMember($member)->assertJsonPath('data.has_active_membership', true);
    }

    public function test_has_active_membership_is_true_with_a_live_company_plan(): void
    {
        $member = $this->member();
        $company = Company::factory()->create();
        $member->companies()->attach($company->id);
        Membership::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        $this->showAsMember($member)->assertJsonPath('data.has_active_membership', true);
    }

    public function test_has_active_membership_is_false_for_an_ended_or_upcoming_plan(): void
    {
        $member = $this->member();
        Membership::factory()->create([
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'current_period_start' => now()->subDays(40),
            'current_period_end' => now()->subDays(10),
        ]);
        Membership::factory()->create([
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'current_period_start' => now()->addDay(),
            'current_period_end' => now()->addDays(31),
        ]);

        $this->showAsMember($member)->assertJsonPath('data.has_active_membership', false);
    }
}
