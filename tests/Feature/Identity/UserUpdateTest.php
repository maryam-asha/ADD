<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * `UserController::update()` now returns `{"message": ...}` instead of the
 * full resource (admin surface convention change mirroring the earlier
 * member-surface change) — this is the first HTTP-level coverage of that
 * endpoint.
 */
class UserUpdateTest extends IdentityTestCase
{
    public function test_admin_can_update_a_users_profile_fields_and_gets_back_a_message(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $target = User::factory()->create(['name' => 'Old Name']);
        $target->assignRole('member');

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/users/{$target->id}", [
            'name' => 'New Name',
            'phone' => $target->phone,
            'email' => $target->email,
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'User updated.']);

        $this->assertSame('New Name', $target->fresh()->name);
    }
}
