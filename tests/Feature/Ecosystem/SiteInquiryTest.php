<?php

namespace Tests\Feature\Ecosystem;

use App\Domain\Ecosystem\Enums\SiteInquiryStatus;
use App\Domain\Ecosystem\Models\SiteInquiry;
use App\Domain\Identity\Models\User;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SiteInquiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);
    }

    public function test_public_user_can_submit_inquiry(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');

        $response = $this->postJson('/api/v1/site-inquiries', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '+963912345678',
            'subject' => 'Private Office Pricing',
            'message' => 'I would like to inquire about monthly desk pricing.',
        ]);

        $response->assertCreated();
        $response->assertJson(['message' => 'Your inquiry has been received. Thank you for reaching out.']);

        $this->assertDatabaseHas('site_inquiries', [
            'name' => 'John Doe',
            'phone' => '+963912345678',
            'status' => 'new',
        ]);

        // Verify operator received in-app notification
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_inquiry_requires_valid_fields(): void
    {
        $response = $this->postJson('/api/v1/site-inquiries', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name', 'phone', 'message']);
    }

    public function test_admin_can_list_and_filter_inquiries(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['dashboard']);

        SiteInquiry::create([
            'name' => 'Alice',
            'phone' => '111',
            'message' => 'Message 1',
            'status' => SiteInquiryStatus::New,
        ]);

        SiteInquiry::create([
            'name' => 'Bob',
            'phone' => '222',
            'message' => 'Message 2',
            'status' => SiteInquiryStatus::Resolved,
        ]);

        $response = $this->getJson('/api/v1/admin/site-inquiries?status=new');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Alice');
    }

    public function test_admin_can_update_inquiry_status_and_assignment(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['dashboard']);

        $inquiry = SiteInquiry::create([
            'name' => 'Charlie',
            'phone' => '333',
            'message' => 'Need help',
            'status' => SiteInquiryStatus::New,
        ]);

        $response = $this->patchJson("/api/v1/admin/site-inquiries/{$inquiry->id}", [
            'status' => 'resolved',
            'assigned_to_user_id' => $admin->id,
            'notes' => 'Called back and answered all questions.',
        ]);

        $response->assertOk();
        $response->assertJson(['message' => 'Inquiry updated.']);

        $this->assertEquals('resolved', $inquiry->fresh()->status->value);
        $this->assertEquals($admin->id, $inquiry->fresh()->assigned_to_user_id);
        $this->assertNotNull($inquiry->fresh()->resolved_at);
    }
}
