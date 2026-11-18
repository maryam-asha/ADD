<?php

namespace Tests\Feature\Member;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicDirectoryConsentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_a_member_can_grant_public_directory_consent(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->withHeader('lang', 'en')->patchJson('/api/v1/member/consents/public-directory', ['granted' => true]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Consent updated.');
        $this->assertDatabaseHas('consents', [
            'subject_type' => 'user',
            'subject_id' => $member->id,
            'consent_type' => 'public_directory',
        ]);

        $this->getJson('/api/v1/member/consents/public-directory')
            ->assertOk()
            ->assertJsonPath('granted', true);
    }

    public function test_a_member_can_revoke_public_directory_consent(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();
        $response = $this->withHeader('lang', 'en')->patchJson('/api/v1/member/consents/public-directory', ['granted' => false]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Consent updated.');

        $this->getJson('/api/v1/member/consents/public-directory')
            ->assertOk()
            ->assertJsonPath('granted', false);
    }

    public function test_re_granting_after_revoke_creates_a_new_row_preserving_history(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => false])->assertOk();
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();

        $this->assertDatabaseCount('consents', 2);
    }

    public function test_granting_twice_in_a_row_does_not_create_a_duplicate_active_row(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();

        $this->assertDatabaseCount('consents', 1);
    }

    public function test_reading_consent_state_with_no_grant_ever_made_returns_false(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->getJson('/api/v1/member/consents/public-directory');

        $response->assertOk();
        $response->assertJsonPath('granted', false);
    }

    public function test_reading_consent_state_after_granting_returns_true(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();

        $response = $this->getJson('/api/v1/member/consents/public-directory');

        $response->assertOk();
        $response->assertJsonPath('granted', true);
    }

    public function test_reading_consent_state_after_granting_then_revoking_returns_false(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => false])->assertOk();

        $response = $this->getJson('/api/v1/member/consents/public-directory');

        $response->assertOk();
        $response->assertJsonPath('granted', false);
    }
}
