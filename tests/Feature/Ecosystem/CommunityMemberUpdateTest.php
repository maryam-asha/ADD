<?php

namespace Tests\Feature\Ecosystem;

use App\Domain\Ecosystem\Models\CommunityMember;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `CommunityMemberController::update()` now returns `{"message": ...}`
 * instead of the full resource (admin surface convention change mirroring
 * the earlier member-surface change) — this is the first HTTP-level
 * coverage of that endpoint.
 */
class CommunityMemberUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_update_a_community_member_and_gets_back_a_message(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $communityMember = CommunityMember::create([
            'name' => ['ar' => 'لينا حداد', 'en' => 'Lina Haddad'],
            'category' => 'investors',
            'order' => 0,
            'published' => true,
        ]);

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/community-members/{$communityMember->id}", [
            'name' => ['ar' => 'لينا حداد', 'en' => 'Lina Haddad'],
            'category' => 'pioneers',
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Community member updated.']);

        $this->assertSame('pioneers', $communityMember->fresh()->category);
    }
}
