<?php

namespace Tests\Feature\Admin;

use App\Domain\Identity\Models\ErrorLog;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Api\V1\Admin\ErrorLogController deliberately does not extend
 * AdminResourceController — index() is paginated (a client-reported-crash
 * table can grow quickly, unlike the small bounded content resources), and
 * destroy() is role:admin only while index/show sit at the group's
 * role:admin|operations (docs/superpowers/specs/
 * 2026-08-11-mobile-error-logging-design.md).
 */
class ErrorLogControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createLog(array $overrides = []): ErrorLog
    {
        return ErrorLog::create(array_merge([
            'error_type' => 'NullPointerException',
            'message' => 'Something broke',
            'stack_trace' => 'at Foo.bar (Foo.java:42)',
            'platform' => 'android',
            'occurred_at' => now(),
        ], $overrides));
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    private function actingAsMember(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    // -------------------------------------------------------------------
    // index
    // -------------------------------------------------------------------

    public function test_index_is_rejected_for_guests(): void
    {
        $this->getJson('/api/v1/admin/error-logs')->assertUnauthorized();
    }

    public function test_index_is_forbidden_for_members(): void
    {
        $this->actingAsMember();

        $this->getJson('/api/v1/admin/error-logs')->assertForbidden();
    }

    public function test_an_admin_can_list_error_logs_paginated(): void
    {
        $this->actingAsAdmin();

        $this->createLog();
        $this->createLog();

        $response = $this->getJson('/api/v1/admin/error-logs');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_an_operations_user_can_also_list_error_logs(): void
    {
        $this->actingAsOperations();

        $this->createLog();

        $this->getJson('/api/v1/admin/error-logs')->assertOk();
    }

    public function test_index_filters_by_platform(): void
    {
        $this->actingAsAdmin();

        $this->createLog(['platform' => 'android']);
        $this->createLog(['platform' => 'android']);
        $this->createLog(['platform' => 'ios']);

        $response = $this->getJson('/api/v1/admin/error-logs?platform=android');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_index_filters_by_error_type(): void
    {
        $this->actingAsAdmin();

        $this->createLog(['error_type' => 'NullPointerException']);
        $this->createLog(['error_type' => 'NullPointerException']);
        $this->createLog(['error_type' => 'IndexOutOfBoundsException']);

        $response = $this->getJson('/api/v1/admin/error-logs?error_type=IndexOutOfBoundsException');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.error_type', 'IndexOutOfBoundsException');
    }

    // -------------------------------------------------------------------
    // show
    // -------------------------------------------------------------------

    public function test_show_is_rejected_for_guests(): void
    {
        $log = $this->createLog();

        $this->getJson("/api/v1/admin/error-logs/{$log->id}")->assertUnauthorized();
    }

    public function test_show_is_forbidden_for_members(): void
    {
        $this->actingAsMember();

        $log = $this->createLog();

        $this->getJson("/api/v1/admin/error-logs/{$log->id}")->assertForbidden();
    }

    public function test_an_admin_can_view_full_detail_including_stack_trace(): void
    {
        $this->actingAsAdmin();

        $log = $this->createLog(['stack_trace' => "at Foo.bar (Foo.java:42)\nat Baz.qux (Baz.java:10)"]);

        $response = $this->getJson("/api/v1/admin/error-logs/{$log->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $log->id);
        $response->assertJsonPath('data.stack_trace', "at Foo.bar (Foo.java:42)\nat Baz.qux (Baz.java:10)");
    }

    public function test_an_operations_user_can_also_view_a_single_error_log(): void
    {
        $this->actingAsOperations();

        $log = $this->createLog();

        $this->getJson("/api/v1/admin/error-logs/{$log->id}")->assertOk();
    }

    // -------------------------------------------------------------------
    // destroy
    // -------------------------------------------------------------------

    public function test_destroy_is_forbidden_for_operations(): void
    {
        $this->actingAsOperations();

        $log = $this->createLog();

        $this->deleteJson("/api/v1/admin/error-logs/{$log->id}")->assertForbidden();

        $this->assertDatabaseHas('error_logs', ['id' => $log->id]);
    }

    public function test_destroy_is_forbidden_for_members(): void
    {
        $this->actingAsMember();

        $log = $this->createLog();

        $this->deleteJson("/api/v1/admin/error-logs/{$log->id}")->assertForbidden();

        $this->assertDatabaseHas('error_logs', ['id' => $log->id]);
    }

    public function test_an_admin_can_delete_an_error_log(): void
    {
        $this->actingAsAdmin();

        $log = $this->createLog();

        $this->deleteJson("/api/v1/admin/error-logs/{$log->id}")->assertNoContent();

        $this->assertDatabaseMissing('error_logs', ['id' => $log->id]);
        $this->assertDatabaseCount('error_logs', 0);
    }
}
