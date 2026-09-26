<?php

namespace Tests\Feature\Services;

use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Forms\Models\FormField;
use App\Domain\Identity\Models\User;
use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceType;
use App\Support\Forms\FieldType;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemberServiceUnifiedContractTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        Sanctum::actingAs($this->member, ['*']);
    }

    private function createAxisAndService(array $typeOverrides = []): array
    {
        $axis = ServiceAxis::create([
            'slug' => 'workspace-support',
            'name' => ['en' => 'Workspace Support', 'ar' => 'دعم مساحات العمل'],
            'color' => '#2563eb',
            'order' => 1,
        ]);

        $service = ServiceType::create(array_merge([
            'service_axis_id' => $axis->id,
            'slug' => 'cleaning-request',
            'name' => ['en' => 'Office Cleaning', 'ar' => 'تنظيف المكاتب'],
            'description' => ['en' => 'Request desk or office cleaning', 'ar' => 'طلب تنظيف المكتب أو المساحة'],
            'icon' => 'sparkles',
            'is_active' => true,
            'is_coming_soon' => false,
            'requires_location' => true,
            'requires_review' => true,
            'visibility' => 'both',
            'request_method' => 'form',
            'order' => 1,
        ], $typeOverrides));

        $form = $service->formOrCreate(FormPurpose::Request);

        $field1 = FormField::create([
            'form_id' => $form->id,
            'key' => 'room_number',
            'label' => ['en' => 'Room / Desk Number', 'ar' => 'رقم الغرفة أو المكتب'],
            'placeholder' => ['en' => 'e.g. Room 204', 'ar' => 'مثال: غرفة 204'],
            'type' => FieldType::Text,
            'validation' => ['min' => 1, 'max' => 50],
            'is_required' => true,
            'is_active' => true,
            'order' => 1,
        ]);

        $field2 = FormField::create([
            'form_id' => $form->id,
            'key' => 'cleaning_type',
            'label' => ['en' => 'Cleaning Type', 'ar' => 'نوع التنظيف'],
            'placeholder' => ['en' => 'Select type', 'ar' => 'اختر النوع'],
            'type' => FieldType::Select,
            'options' => [
                ['value' => 'standard', 'label' => ['en' => 'Standard Clean', 'ar' => 'تنظيف عادي']],
                ['value' => 'deep', 'label' => ['en' => 'Deep Clean', 'ar' => 'تنظيف شامل']],
            ],
            'is_required' => true,
            'is_active' => true,
            'order' => 2,
        ]);

        return [$axis, $service, [$field1, $field2]];
    }

    public function test_services_catalog_index_does_not_include_form_fields(): void
    {
        $this->createAxisAndService();

        $response = $this->getJson('/api/v1/member/services', ['lang' => 'en']);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'slug',
                        'name',
                        'color',
                        'service_types' => [
                            '*' => [
                                'id',
                                'slug',
                                'name',
                                'description',
                                'icon',
                                'is_active',
                                'is_coming_soon',
                                'status',
                                'can_request',
                                'request_method',
                            ],
                        ],
                    ],
                ],
            ]);

        $firstType = $response->json('data.0.service_types.0');
        $this->assertArrayNotHasKey('fields', $firstType, 'Catalog index must not expose fields');
        $this->assertArrayNotHasKey('registration_form', $firstType, 'Catalog index must not expose registration_form');
        $this->assertArrayNotHasKey('title', $firstType);
        $this->assertArrayNotHasKey('requires_location', $firstType);
        $this->assertArrayNotHasKey('requires_review', $firstType);
        $this->assertArrayNotHasKey('requires_approval', $firstType);
        $this->assertArrayNotHasKey('has_form', $firstType);
        $this->assertArrayNotHasKey('has_registration_form', $firstType);
        $this->assertArrayNotHasKey('request_config', $firstType);
        $this->assertSame('Office Cleaning', $firstType['name']);
        $this->assertSame('open', $firstType['status']);
        $this->assertTrue($firstType['can_request']);
    }

    public function test_service_show_details_includes_form_fields_with_unified_keys(): void
    {
        [$axis, $service] = $this->createAxisAndService();

        $response = $this->getJson("/api/v1/member/services/{$service->slug}", ['lang' => 'en']);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertSame('Office Cleaning', $data['name']);
        $this->assertSame('open', $data['status']);
        $this->assertTrue($data['can_request']);
        $this->assertArrayNotHasKey('title', $data);
        $this->assertArrayNotHasKey('requires_location', $data);
        $this->assertArrayNotHasKey('requires_review', $data);
        $this->assertArrayNotHasKey('requires_approval', $data);
        $this->assertArrayNotHasKey('has_form', $data);
        $this->assertArrayNotHasKey('has_registration_form', $data);
        $this->assertArrayNotHasKey('request_config', $data);
        $this->assertCount(2, $data['registration_form']);
        $this->assertCount(2, $data['fields']);

        $textField = $data['registration_form'][0];
        $this->assertSame('room_number', $textField['key']);
        $this->assertSame('Room / Desk Number', $textField['label']);
        $this->assertSame('e.g. Room 204', $textField['placeholder']);
        $this->assertSame('text', $textField['type']);
        $this->assertTrue($textField['is_required']);
        $this->assertTrue($textField['is_active']);

        $selectField = $data['registration_form'][1];
        $this->assertSame('cleaning_type', $selectField['key']);
        $this->assertSame('select', $selectField['type']);
        $this->assertIsArray($selectField['options']);
        $this->assertCount(2, $selectField['options']);
        $this->assertSame('standard', $selectField['options'][0]['value']);
        $this->assertSame('Standard Clean', $selectField['options'][0]['label']);
    }

    public function test_service_registration_form_endpoint_returns_unified_payload(): void
    {
        [$axis, $service] = $this->createAxisAndService();

        $response = $this->getJson("/api/v1/member/services/{$service->id}/registration-form");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'external_form_url' => null,
                    'request_method' => 'form',
                ],
            ])
            ->assertJsonCount(2, 'data.registration_form');

        // Also test request-form alias
        $aliasResponse = $this->getJson("/api/v1/member/services/{$service->id}/request-form");
        $aliasResponse->assertStatus(200)
            ->assertJsonCount(2, 'data.registration_form');
    }

    public function test_coming_soon_service_sets_proper_status_and_can_request(): void
    {
        [$axis, $service] = $this->createAxisAndService(['is_coming_soon' => true]);

        $response = $this->getJson("/api/v1/member/services/{$service->slug}");

        $response->assertStatus(200);
        $this->assertSame('coming_soon', $response->json('data.status'));
        $this->assertFalse($response->json('data.can_request'));
    }

    public function test_member_can_fetch_service_axes_separately(): void
    {
        $this->createAxisAndService();

        $response = $this->getJson('/api/v1/member/services/axes', ['lang' => 'en']);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'slug',
                        'name',
                        'color',
                        'services_count',
                    ],
                ],
            ]);

        $firstAxis = $response->json('data.0');
        $this->assertSame('workspace-support', $firstAxis['slug']);
        $this->assertSame('Workspace Support', $firstAxis['name']);
        $this->assertSame(1, $firstAxis['services_count']);
        $this->assertArrayNotHasKey('service_types', $firstAxis, 'Axes endpoint must only return axes without service_types');

        // Test alias /services/categories
        $aliasResponse = $this->getJson('/api/v1/member/services/categories');
        $aliasResponse->assertStatus(200);
    }

    public function test_member_can_fetch_services_for_a_specific_axis_separately(): void
    {
        [$axis, $service] = $this->createAxisAndService();

        $response = $this->getJson("/api/v1/member/services/axes/{$axis->slug}", ['lang' => 'en']);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'slug',
                    'name',
                    'color',
                    'service_types' => [
                        '*' => [
                            'id',
                            'slug',
                            'name',
                            'status',
                            'can_request',
                        ],
                    ],
                ],
            ]);

        $data = $response->json('data');
        $this->assertSame('workspace-support', $data['slug']);
        $this->assertCount(1, $data['service_types']);
        $this->assertSame('cleaning-request', $data['service_types'][0]['slug']);
    }

    public function test_member_can_fetch_all_services_as_flat_list_without_axis_grouping(): void
    {
        [$axis, $service] = $this->createAxisAndService();

        $response = $this->getJson('/api/v1/member/services/all', ['lang' => 'en']);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'slug',
                        'name',
                        'description',
                        'status',
                        'can_request',
                        'axis' => [
                            'id',
                            'slug',
                            'name',
                            'color',
                        ],
                    ],
                ],
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
            ]);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('cleaning-request', $data[0]['slug']);
        $this->assertSame('workspace-support', $data[0]['axis']['slug']);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('meta.per_page', 15);

        // Test ?flat=1 on /services
        $flatResponse = $this->getJson('/api/v1/member/services?flat=1', ['lang' => 'en']);
        $flatResponse->assertStatus(200)
            ->assertJsonCount(1, 'data');
        $this->assertSame('cleaning-request', $flatResponse->json('data.0.slug'));
    }

    public function test_flat_service_list_is_paginated(): void
    {
        [$axis] = $this->createAxisAndService();

        // 24 more alongside the helper's one: enough to span pages at the
        // default 15 and still leave a short last page.
        foreach (range(1, 24) as $i) {
            ServiceType::create([
                'service_axis_id' => $axis->id,
                'slug' => "extra-service-{$i}",
                'name' => ['en' => "Extra {$i}", 'ar' => "خدمة {$i}"],
                'description' => ['en' => 'Filler', 'ar' => 'حشو'],
                'icon' => 'sparkles',
                'is_active' => true,
                'is_coming_soon' => false,
                'requires_location' => false,
                'requires_review' => false,
                'visibility' => 'both',
                'request_method' => 'form',
                'order' => $i + 1,
            ]);
        }

        // Default page size.
        $first = $this->getJson('/api/v1/member/services/all', ['lang' => 'en']);
        $first->assertOk();
        $first->assertJsonCount(15, 'data');
        $first->assertJsonPath('meta.total', 25);
        $first->assertJsonPath('meta.last_page', 2);
        $first->assertJsonPath('meta.current_page', 1);

        // Second page carries the remainder.
        $second = $this->getJson('/api/v1/member/services/all?page=2', ['lang' => 'en']);
        $second->assertOk();
        $second->assertJsonCount(10, 'data');
        $second->assertJsonPath('meta.current_page', 2);

        // Explicit per_page, and the 100 clamp holds.
        $sized = $this->getJson('/api/v1/member/services/all?per_page=5', ['lang' => 'en']);
        $sized->assertOk();
        $sized->assertJsonCount(5, 'data');
        $sized->assertJsonPath('meta.last_page', 5);

        $clamped = $this->getJson('/api/v1/member/services/all?per_page=500', ['lang' => 'en']);
        $clamped->assertOk();
        $clamped->assertJsonPath('meta.per_page', 100);

        // ?flat=1 shares the same paginated envelope.
        $flat = $this->getJson('/api/v1/member/services?flat=1&per_page=5', ['lang' => 'en']);
        $flat->assertOk();
        $flat->assertJsonCount(5, 'data');
        $flat->assertJsonPath('meta.total', 25);
    }

    public function test_submitting_service_request_returns_only_success_message(): void
    {
        [$axis, $service] = $this->createAxisAndService();

        $response = $this->postJson("/api/v1/member/services/{$service->id}/requests", [
            'fields' => [
                'room_number' => 'Room 101',
                'cleaning_type' => 'standard',
            ],
        ], ['lang' => 'en']);

        $response->assertCreated()
            ->assertExactJson([
                'message' => 'Office Cleaning request submitted successfully.',
            ]);

        // Also test Arabic message
        $responseAr = $this->postJson("/api/v1/member/services/{$service->id}/requests", [
            'fields' => [
                'room_number' => 'Room 102',
                'cleaning_type' => 'deep',
            ],
        ], ['lang' => 'ar']);

        $responseAr->assertCreated()
            ->assertExactJson([
                'message' => 'تم تقديم طلب تنظيف المكاتب بنجاح.',
            ]);
    }
}
