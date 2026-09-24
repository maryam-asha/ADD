<?php

namespace Tests\Feature\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceRequest;
use App\Domain\Services\Models\ServiceType;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The member's own service-request history shares the envelope every other
 * member listing uses: rows under `data`, four page counters under `meta`,
 * and no `links` block.
 */
class MemberServiceRequestsListTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsMemberWithRequests(int $count): User
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('member');

        $axis = ServiceAxis::create([
            'slug' => 'workspace-support',
            'name' => ['en' => 'Workspace Support', 'ar' => 'دعم مساحات العمل'],
            'color' => '#2563eb',
            'order' => 1,
        ]);

        $type = ServiceType::create([
            'service_axis_id' => $axis->id,
            'slug' => 'cleaning-request',
            'name' => ['en' => 'Office Cleaning', 'ar' => 'تنظيف المكاتب'],
            'description' => ['en' => 'Request cleaning', 'ar' => 'طلب تنظيف'],
            'icon' => 'sparkles',
            'is_active' => true,
            'is_coming_soon' => false,
            'requires_location' => false,
            'requires_review' => true,
            'visibility' => 'both',
            'request_method' => 'form',
            'order' => 1,
        ]);

        foreach (range(1, $count) as $i) {
            ServiceRequest::create([
                'service_type_id' => $type->id,
                'user_id' => $user->id,
                'status' => 'pending',
                'submitted_at' => now()->subMinutes($i),
            ]);
        }

        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_list_returns_data_and_meta_without_links(): void
    {
        $this->actingAsMemberWithRequests(3);

        $response = $this->getJson('/api/v1/member/service-requests');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

        // The two things that made this endpoint differ from the rest.
        $response->assertJsonMissingPath('links');
        $response->assertJsonMissingPath('meta.links');
        $response->assertJsonMissingPath('meta.path');

        $this->assertSame(
            ['current_page', 'last_page', 'per_page', 'total'],
            array_keys($response->json('meta'))
        );

        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.per_page', 15);
    }

    public function test_list_paginates(): void
    {
        $this->actingAsMemberWithRequests(20);

        $first = $this->getJson('/api/v1/member/service-requests');
        $first->assertOk();
        $first->assertJsonCount(15, 'data');
        $first->assertJsonPath('meta.last_page', 2);

        $second = $this->getJson('/api/v1/member/service-requests?page=2');
        $second->assertOk();
        $second->assertJsonCount(5, 'data');
        $second->assertJsonPath('meta.current_page', 2);

        $sized = $this->getJson('/api/v1/member/service-requests?per_page=5');
        $sized->assertOk();
        $sized->assertJsonCount(5, 'data');
        $sized->assertJsonPath('meta.last_page', 4);

        // Out-of-range per_page is clamped, not rejected.
        $clamped = $this->getJson('/api/v1/member/service-requests?per_page=500');
        $clamped->assertOk();
        $clamped->assertJsonPath('meta.per_page', 100);
    }

    public function test_a_member_only_sees_their_own_requests(): void
    {
        $this->actingAsMemberWithRequests(2);

        $other = User::factory()->create();
        $other->assignRole('member');
        Sanctum::actingAs($other, ['*']);

        $response = $this->getJson('/api/v1/member/service-requests');
        $response->assertOk();
        $response->assertJsonCount(0, 'data');
        $response->assertJsonPath('meta.total', 0);
    }

}
