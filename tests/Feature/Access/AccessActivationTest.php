<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('operations');
        Sanctum::actingAs($admin, ['*']);
    }

    public function test_activating_an_issued_grant_succeeds_and_logs_reception_activation(): void
    {
        $grant = AccessGrant::factory()->create(['status' => AccessGrantStatus::Issued]);

        $response = $this->postJson("/api/v1/admin/reception/access-grants/{$grant->id}/activate");

        $response->assertOk();
        $this->assertSame(AccessGrantStatus::Activated, $grant->fresh()->status);
        $this->assertNotNull($grant->fresh()->activated_at);
        $this->assertDatabaseHas('access_events', [
            'access_grant_id' => $grant->id,
            'channel' => AccessEventChannel::ReceptionActivation->value,
        ]);
    }

    public function test_activating_an_already_activated_grant_returns_409(): void
    {
        $grant = AccessGrant::factory()->activated()->create();

        $this->postJson("/api/v1/admin/reception/access-grants/{$grant->id}/activate")->assertStatus(409);
    }

    public function test_activating_a_revoked_grant_returns_409(): void
    {
        $grant = AccessGrant::factory()->revoked()->create();

        $this->postJson("/api/v1/admin/reception/access-grants/{$grant->id}/activate")->assertStatus(409);
    }
}
