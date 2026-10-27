<?php

namespace Tests\Feature\Booking;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Who POST /api/broadcasting/auth signs a private channel for
 * (routes/channels.php). Run against the pusher driver with made-up keys —
 * signing is local, nothing is sent — because the `log` and `null` drivers
 * the app runs on locally authorize everything without asking the channel
 * rules at all.
 */
class BroadcastChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => '1',
            'broadcasting.connections.pusher.options.cluster' => 'eu',
        ]);

        // The rules were registered at boot on the then-default (null)
        // broadcaster; give them to this one.
        require base_path('routes/channels.php');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function authorize(string $channel): TestResponse
    {
        return $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
    }

    public function test_a_member_may_listen_on_their_own_channel_only(): void
    {
        $member = $this->userWithRole('member');
        $other = $this->userWithRole('member');
        Sanctum::actingAs($member, ['member-app']);

        $this->authorize("private-member.{$member->id}")->assertOk()->assertJsonStructure(['auth']);
        $this->authorize("private-member.{$other->id}")->assertForbidden();
    }

    public function test_a_member_may_not_listen_to_the_desk(): void
    {
        Sanctum::actingAs($this->userWithRole('member'), ['member-app']);

        $this->authorize('private-reception')->assertForbidden();
    }

    public function test_operations_and_admin_on_the_dashboard_may_listen_to_the_desk(): void
    {
        Sanctum::actingAs($this->userWithRole('operations'), ['dashboard']);
        $this->authorize('private-reception')->assertOk()->assertJsonStructure(['auth']);

        Sanctum::actingAs($this->userWithRole('admin'), ['dashboard']);
        $this->authorize('private-reception')->assertOk();
    }

    /** By permission, not role name: any role that may see the desk hears it. */
    public function test_a_custom_role_with_reception_view_may_listen_to_the_desk_and_one_without_may_not(): void
    {
        Role::create(['name' => 'front-desk', 'guard_name' => 'web'])->givePermissionTo('reception.view');
        Sanctum::actingAs($this->userWithRole('front-desk'), ['dashboard']);
        $this->authorize('private-reception')->assertOk();

        Role::create(['name' => 'stack_holder', 'guard_name' => 'web'])->givePermissionTo('amenities.view');
        Sanctum::actingAs($this->userWithRole('stack_holder'), ['dashboard']);
        $this->authorize('private-reception')->assertForbidden();
    }

    /** The member app's token never reaches the desk, even for someone who also works it. */
    public function test_a_member_app_token_of_a_desk_worker_is_refused_the_desk(): void
    {
        $both = $this->userWithRole('operations');
        $both->assignRole('member');
        Sanctum::actingAs($both, ['member-app']);

        $this->authorize('private-reception')->assertForbidden();
        $this->authorize("private-member.{$both->id}")->assertOk();
    }

    public function test_a_guest_is_refused(): void
    {
        $this->authorize('private-reception')->assertUnauthorized();
    }
}
