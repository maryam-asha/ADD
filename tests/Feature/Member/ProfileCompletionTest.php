<?php

namespace Tests\Feature\Member;

use App\Domain\Identity\Models\User;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    public function test_get_profile_reports_score_25_and_the_seeded_threshold_for_a_fresh_member(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->getJson('/api/v1/member/profile');

        $response->assertOk();
        $response->assertJsonPath('data.completion.score', 25);
        $response->assertJsonPath('data.completion.threshold', 80);
        $response->assertJsonMissingPath('data.completion.missing_fields');
        $response->assertJsonMissingPath('completion');
        $this->assertNotNull($response->json('data.user'));
    }

    public function test_get_profile_score_rises_after_the_member_fills_in_fields(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->patchJson('/api/v1/member/profile', [
            'bio' => 'Coffee and code.',
            'avatar_url' => 'https://example.com/avatar.png',
        ])->assertOk();

        $response = $this->getJson('/api/v1/member/profile');

        $response->assertOk();
        $response->assertJsonPath('data.completion.score', 25 + 10 + 15);
        $response->assertJsonMissingPath('data.completion.missing_fields');
    }

    public function test_changing_the_threshold_setting_changes_the_reported_threshold_without_a_code_change(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        app(SettingService::class)->set('profile.completion_threshold', 50, SettingValueType::Int);

        $response = $this->getJson('/api/v1/member/profile');

        $response->assertOk();
        $response->assertJsonPath('data.completion.threshold', 50);
    }
}
