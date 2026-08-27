<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Booking\Models\Booking;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessActivationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('operations');
        Sanctum::actingAs($this->admin, ['*']);
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
            'actor_user_id' => $this->admin->id,
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

    /**
     * Final-review C3b — reception's per-booking read surface for the
     * access-grant it needs to activate; previously there was no way to
     * discover a grant's id from its booking, only the reverse.
     */
    public function test_can_fetch_the_most_recent_access_grant_for_a_booking(): void
    {
        $booking = Booking::factory()->create();
        $older = AccessGrant::factory()->create(['source_id' => $booking->id, 'issued_at' => now()->subHour()]);
        $newest = AccessGrant::factory()->create(['source_id' => $booking->id, 'issued_at' => now()]);

        $response = $this->getJson("/api/v1/admin/reception/bookings/{$booking->id}/access-grant");

        $response->assertOk();
        $response->assertExactJson(['id' => $newest->id, 'status' => $newest->status->value]);
        $this->assertNotEquals($older->id, $response->json('id'));
    }

    public function test_fetching_the_access_grant_for_a_booking_with_none_returns_404(): void
    {
        $booking = Booking::factory()->create();

        $this->getJson("/api/v1/admin/reception/bookings/{$booking->id}/access-grant")->assertStatus(404);
    }
}
