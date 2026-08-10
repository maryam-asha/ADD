<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * `User::deactivate()`/`block()` (app/Domain/Identity/Models/User.php) go
 * further than a raw status write (see `SuspendedAccountAccessTest` for
 * that weaker case): they delete every access token the account holds, in
 * the same transaction as the status change. A request replayed with that
 * exact token afterward must come back **401**, not 403 — the token itself
 * is gone from `personal_access_tokens`, Sanctum has nothing left to
 * authenticate, this is not the `EnsureUserIsActive` guard rejecting an
 * otherwise-valid session.
 */
class AccountDeactivationTest extends IdentityTestCase
{
    public function test_deactivating_a_user_deletes_their_tokens_and_a_replayed_token_gets_401_not_403(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $token = $member->createToken('member-app')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/member/guests')
            ->assertOk();

        $this->assertSame(1, $member->tokens()->count());

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Auth::forgetGuards();
        $this->actingAs($admin);
        $member->deactivate('left the company');

        $member->refresh();
        $this->assertSame('deactivated', $member->status);
        $this->assertSame('left the company', $member->status_reason);
        $this->assertNotNull($member->status_changed_at);
        $this->assertSame($admin->id, $member->status_changed_by);
        $this->assertSame(0, $member->tokens()->count());

        Auth::forgetGuards();
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/member/guests');

        $response->assertUnauthorized();
    }

    public function test_blocking_a_user_deletes_their_tokens_and_a_replayed_token_gets_401(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $token = $member->createToken('member-app')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/member/guests')
            ->assertOk();

        $member->block('fraudulent activity');

        $member->refresh();
        $this->assertSame('blocked', $member->status);
        $this->assertSame('fraudulent activity', $member->status_reason);
        $this->assertSame(0, $member->tokens()->count());

        Auth::forgetGuards();
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/member/guests');

        $response->assertUnauthorized();
    }

    public function test_reactivating_a_user_does_not_touch_tokens_since_none_survive_deactivation(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $member->deactivate('temporary pause');

        $member->activate('back from leave');

        $member->refresh();
        $this->assertSame('active', $member->status);
        $this->assertSame('back from leave', $member->status_reason);
        $this->assertSame(0, $member->tokens()->count());

        $newToken = $member->createToken('member-app')->plainTextToken;
        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$newToken}")
            ->getJson('/api/v1/member/guests')
            ->assertOk();
    }

    public function test_the_admin_status_endpoint_routes_through_the_new_model_methods_and_revokes_the_target_users_tokens(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $target = User::factory()->create();
        $target->assignRole('member');
        $targetToken = $target->createToken('member-app')->plainTextToken;

        $this->actingAs($admin);
        $this->patchJson("/api/v1/admin/users/{$target->id}/status", [
            'status' => 'blocked',
            'reason' => 'policy violation',
        ])->assertOk();

        $target->refresh();
        $this->assertSame('blocked', $target->status);
        $this->assertSame('policy violation', $target->status_reason);
        $this->assertSame($admin->id, $target->status_changed_by);
        $this->assertSame(0, $target->tokens()->count());

        Auth::forgetGuards();
        $this->withHeader('Authorization', "Bearer {$targetToken}")
            ->getJson('/api/v1/member/guests')
            ->assertUnauthorized();
    }
}
