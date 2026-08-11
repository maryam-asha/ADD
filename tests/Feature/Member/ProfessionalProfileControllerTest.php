<?php

namespace Tests\Feature\Member;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfessionalProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_a_member_can_fill_their_professional_profile(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->patchJson('/api/v1/member/profile/professional', [
            'job_title' => 'Founder',
            'company_name' => 'ACME',
            'industry' => 'Software',
            'linkedin_url' => 'https://linkedin.com/in/example',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.job_title', 'Founder');
        $this->assertDatabaseHas('user_professional_profiles', [
            'user_id' => $member->id,
            'company_name' => 'ACME',
        ]);
    }

    public function test_updating_again_upserts_rather_than_creating_a_second_row(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->patchJson('/api/v1/member/profile/professional', ['job_title' => 'Founder'])->assertOk();
        $this->patchJson('/api/v1/member/profile/professional', ['job_title' => 'CEO'])->assertOk();

        $this->assertDatabaseCount('user_professional_profiles', 1);
        $this->assertDatabaseHas('user_professional_profiles', ['user_id' => $member->id, 'job_title' => 'CEO']);
    }

    public function test_show_returns_null_fields_when_no_profile_exists_yet(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->getJson('/api/v1/member/profile/professional');

        $response->assertOk();
        $response->assertJsonPath('data.job_title', null);
    }
}
