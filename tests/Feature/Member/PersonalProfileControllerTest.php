<?php

namespace Tests\Feature\Member;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PersonalProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_a_member_can_fill_their_personal_profile(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->patchJson('/api/v1/member/profile/personal', [
            'bio' => 'Coffee and code.',
            'city' => 'Aleppo',
            'avatar_url' => 'https://example.com/avatar.png',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.bio', 'Coffee and code.');
        $this->assertDatabaseHas('user_personal_profiles', [
            'user_id' => $member->id,
            'city' => 'Aleppo',
        ]);
    }

    public function test_updating_again_upserts_rather_than_creating_a_second_row(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->patchJson('/api/v1/member/profile/personal', ['city' => 'Aleppo'])->assertOk();
        $this->patchJson('/api/v1/member/profile/personal', ['city' => 'Damascus'])->assertOk();

        $this->assertDatabaseCount('user_personal_profiles', 1);
        $this->assertDatabaseHas('user_personal_profiles', ['user_id' => $member->id, 'city' => 'Damascus']);
    }

    public function test_show_returns_null_fields_when_no_profile_exists_yet(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->getJson('/api/v1/member/profile/personal');

        $response->assertOk();
        $response->assertJsonPath('data.bio', null);
    }
}
