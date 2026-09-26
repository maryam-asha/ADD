<?php

namespace Tests\Feature\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Services\Enums\RequestStatus;
use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceRequest;
use App\Domain\Services\Models\ServiceRequestValue;
use App\Domain\Services\Models\ServiceType;
use App\Domain\Services\Models\ServiceTypeField;
use App\Domain\Services\Services\ServiceRequestService;
use App\Support\Forms\FieldType;
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

        for ($i = 1; $i <= $count; $i++) {
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

    public function test_each_row_carries_reference_name_and_dates(): void
    {
        $this->actingAsMemberWithRequests(1);

        $response = $this->getJson('/api/v1/member/service-requests');

        $response->assertOk();
        $response->assertJsonPath('data.0.name', 'تنظيف المكاتب');
        $response->assertJsonPath('data.0.service_type', 'دعم مساحات العمل');
        $response->assertJsonPath('data.0.status', 'pending');
        $response->assertJsonPath('data.0.processed_at', null);
        $this->assertNotNull($response->json('data.0.submitted_at.formatted'));
        $this->assertMatchesRegularExpression(
            '/^REQ-\d{4}-\d{5}$/',
            $response->json('data.0.reference'),
        );
    }

    public function test_references_are_sequential_within_a_year_and_restart_the_next(): void
    {
        $this->actingAsMemberWithRequests(0);
        $type = ServiceType::first();
        $userId = User::first()->id;

        $make = fn (string $at) => ServiceRequest::create([
            'service_type_id' => $type->id,
            'user_id' => $userId,
            'status' => 'pending',
            'submitted_at' => $at,
        ])->reference;

        $this->assertSame('REQ-2025-00001', $make('2025-03-01 10:00:00'));
        $this->assertSame('REQ-2025-00002', $make('2025-12-31 23:00:00'));
        $this->assertSame('REQ-2026-00001', $make('2026-01-01 08:00:00'));
    }

    public function test_processed_at_is_stamped_when_operations_closes_the_request(): void
    {
        $this->actingAsMemberWithRequests(1);
        $request = ServiceRequest::first();
        $service = app(ServiceRequestService::class);

        $service->updateStatus($request, RequestStatus::InProgress);
        $this->assertNull($request->fresh()->processed_at);

        $service->updateStatus($request->fresh(), RequestStatus::Resolved);
        $this->assertNotNull($request->fresh()->processed_at);
    }

    public function test_a_list_row_carries_only_what_the_list_shows(): void
    {
        $this->actingAsMemberWithRequests(1);

        $row = $this->getJson('/api/v1/member/service-requests')->assertOk()->json('data.0');

        $this->assertSame(
            ['id', 'reference', 'name', 'description', 'service_type', 'status', 'submitted_at', 'processed_at'],
            array_keys($row),
        );
    }

    public function test_a_resolution_note_is_returned_in_the_request_locale(): void
    {
        $this->actingAsMemberWithRequests(1);
        ServiceRequest::first()->update([
            'status' => 'resolved',
            'resolution_note' => ['en' => 'Cleaned.', 'ar' => 'تم التنظيف.'],
        ]);

        $this->getJson('/api/v1/member/service-requests')
            ->assertOk()
            ->assertJsonPath('data.0.resolution_note', 'تم التنظيف.');
    }

    public function test_dates_are_reported_in_local_time_with_display_labels(): void
    {
        $this->actingAsMemberWithRequests(0);

        ServiceRequest::create([
            'service_type_id' => ServiceType::first()->id,
            'user_id' => User::first()->id,
            'status' => 'resolved',
            // 07:12 UTC is 10:12 in Damascus.
            'submitted_at' => '2026-09-24 07:12:00',
            'processed_at' => '2026-09-24 11:30:00',
        ]);

        $row = $this->getJson('/api/v1/member/service-requests')->assertOk()->json('data.0');

        $this->assertSame([
            'date' => 'Sep 24, 2026',
            'time' => '10:12 AM',
            'formatted' => 'Sep 24, 2026 · 10:12 AM',
        ], $row['submitted_at']);
        $this->assertSame('Sep 24, 2026 · 02:30 PM', $row['processed_at']['formatted']);
    }

    public function test_the_detail_has_the_list_shape_plus_the_form_answers(): void
    {
        $this->actingAsMemberWithRequests(1);
        $request = ServiceRequest::first();

        $field = ServiceTypeField::create([
            'service_type_id' => $request->service_type_id,
            'key' => 'room_number',
            'label' => ['en' => 'Room / Desk Number', 'ar' => 'رقم الغرفة أو المكتب'],
            'type' => FieldType::Text,
            'is_required' => true,
            'is_active' => true,
            'order' => 1,
        ]);
        ServiceRequestValue::fromFieldValue($request->id, $field, 'Room 204')->save();

        $detail = $this->getJson("/api/v1/member/service-requests/{$request->id}")->assertOk()->json('data');

        $this->assertSame(
            ['id', 'reference', 'name', 'description', 'service_type', 'status', 'submitted_at', 'processed_at', 'values'],
            array_keys($detail),
        );
        $this->assertSame('تنظيف المكاتب', $detail['name']);
        $this->assertSame('طلب تنظيف', $detail['description']);
        $this->assertSame('دعم مساحات العمل', $detail['service_type']);
        $this->assertSame([
            ['field_key' => 'room_number', 'label' => 'رقم الغرفة أو المكتب', 'value' => 'Room 204'],
        ], $detail['values']);
    }

    public function test_a_member_cannot_open_someone_elses_request(): void
    {
        $this->actingAsMemberWithRequests(1);
        $request = ServiceRequest::first();

        $other = User::factory()->create();
        $other->assignRole('member');
        Sanctum::actingAs($other, ['*']);

        $this->getJson("/api/v1/member/service-requests/{$request->id}")->assertNotFound();
    }

    public function test_the_list_can_be_filtered_by_status(): void
    {
        $this->actingAsMemberWithRequests(3);
        ServiceRequest::query()->orderBy('id')->first()->update(['status' => 'resolved']);

        $resolved = $this->getJson('/api/v1/member/service-requests?status=resolved')->assertOk();
        $resolved->assertJsonCount(1, 'data');
        $resolved->assertJsonPath('data.0.status', 'resolved');
        $resolved->assertJsonPath('meta.total', 1);

        $pending = $this->getJson('/api/v1/member/service-requests?status=pending')->assertOk();
        $pending->assertJsonCount(2, 'data');
        $pending->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/member/service-requests?status=in_progress')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_unknown_status_is_rejected_rather_than_returning_an_empty_list(): void
    {
        $this->actingAsMemberWithRequests(1);

        $this->getJson('/api/v1/member/service-requests?status=done')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }
}
