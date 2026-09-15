<?php

namespace Tests\Feature\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceRequest;
use App\Domain\Services\Models\ServiceType;
use App\Domain\Services\Models\ServiceTypeField;
use App\Support\Forms\FieldType;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Field rules were built but never applied: the rule builder was called
 * from nowhere, so every submitted payload was persisted unchecked. These
 * assertions are what keep it wired.
 */
class ServiceRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function member(): User
    {
        $user = User::factory()->create();
        $user->assignRole('member');

        return $user;
    }

    private function serviceType(): ServiceType
    {
        $axis = ServiceAxis::create([
            'slug' => 'workspace',
            'name' => ['en' => 'Workspace', 'ar' => 'مساحة'],
            'color' => '#123456',
            'order' => 0,
        ]);

        return ServiceType::create([
            'service_axis_id' => $axis->id,
            'slug' => 'maintenance',
            'name' => ['en' => 'Maintenance', 'ar' => 'صيانة'],
            'is_active' => true,
            'visibility' => 'both',
            'request_method' => 'form',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function field(ServiceType $type, array $attributes): ServiceTypeField
    {
        return ServiceTypeField::create(array_merge([
            'service_type_id' => $type->id,
            'label' => ['en' => 'Question', 'ar' => 'سؤال'],
            'type' => FieldType::Text,
            'is_required' => false,
            'is_active' => true,
            'order' => 0,
        ], $attributes));
    }

    public function test_a_required_field_left_out_is_refused(): void
    {
        $type = $this->serviceType();
        $this->field($type, ['key' => 'description', 'is_required' => true]);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/services/{$type->slug}/requests", ['fields' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.description');

        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_an_answer_past_its_maximum_length_is_refused(): void
    {
        $type = $this->serviceType();
        $this->field($type, ['key' => 'description', 'validation' => ['max' => 10]]);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/services/{$type->slug}/requests", [
            'fields' => ['description' => str_repeat('x', 50)],
        ])->assertStatus(422)->assertJsonValidationErrors('fields.description');
    }

    public function test_a_choice_outside_the_option_set_is_refused(): void
    {
        $type = $this->serviceType();
        $this->field($type, [
            'key' => 'priority',
            'type' => FieldType::Select,
            'options' => [
                ['value' => 'low', 'label' => ['en' => 'Low', 'ar' => 'منخفض']],
                ['value' => 'high', 'label' => ['en' => 'High', 'ar' => 'عالٍ']],
            ],
        ]);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/services/{$type->slug}/requests", [
            'fields' => ['priority' => 'catastrophic'],
        ])->assertStatus(422)->assertJsonValidationErrors('fields.priority');
    }

    public function test_a_valid_submission_still_goes_through(): void
    {
        $type = $this->serviceType();
        $this->field($type, ['key' => 'description', 'is_required' => true, 'validation' => ['max' => 100]]);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/services/{$type->slug}/requests", [
            'fields' => ['description' => 'The air conditioning is leaking.'],
        ])->assertCreated();

        $request = ServiceRequest::with('values')->firstOrFail();
        $this->assertSame('The air conditioning is leaking.', $request->values->first()->resolvedValue());
    }

    public function test_an_inactive_field_is_neither_shown_nor_required(): void
    {
        $type = $this->serviceType();
        $this->field($type, ['key' => 'retired', 'is_required' => true, 'is_active' => false]);

        Sanctum::actingAs($this->member(), ['*']);

        $shown = $this->getJson("/api/v1/member/services/{$type->slug}");
        $shown->assertOk();
        $this->assertSame([], $shown->json('data.fields'));

        // It used to be rendered to the member and then dropped on submit.
        $this->postJson("/api/v1/member/services/{$type->slug}/requests", ['fields' => []])
            ->assertCreated();
    }

    public function test_identity_keys_cannot_be_defined_as_service_fields_either(): void
    {
        $type = $this->serviceType();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        foreach (['name', 'phone', 'email', 'Email'] as $key) {
            $this->postJson("/api/v1/admin/service-types/{$type->id}/fields", [
                'key' => $key,
                'label' => ['en' => 'Your name', 'ar' => 'اسمك'],
                'type' => 'text',
            ])->assertStatus(422)->assertJsonValidationErrors('key');
        }

        $this->assertSame(0, ServiceTypeField::where('service_type_id', $type->id)->count());
    }

    public function test_a_service_field_key_that_would_break_rule_paths_is_refused(): void
    {
        $type = $this->serviceType();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/v1/admin/service-types/{$type->id}/fields", [
            'key' => 'a.b',
            'label' => ['en' => 'Q', 'ar' => 'س'],
            'type' => 'text',
        ])->assertStatus(422)->assertJsonValidationErrors('key');
    }
}
