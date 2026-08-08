<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\ConsentType;
use App\Domain\Identity\Models\Consent;
use App\Domain\Identity\Models\Guest;
use App\Domain\Identity\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * PRD decision #9: a guest has no account and acts only through the
 * hosting member. PRD §5.11: submitting a guest's data on their behalf is
 * itself a consent-worthy act.
 */
class GuestTest extends IdentityTestCase
{
    public function test_a_member_can_create_a_guest_and_a_consent_is_recorded(): void
    {
        $host = User::factory()->create();
        $host->assignRole('member');
        Sanctum::actingAs($host, ['*']);

        $response = $this->postJson('/api/v1/member/guests', [
            'full_name' => 'Visitor X',
            'phone' => '0955555555',
            'valid_until' => now()->addDay()->toISOString(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.full_name', 'Visitor X');

        $this->assertDatabaseHas('guests', [
            'hosting_user_id' => $host->id,
            'full_name' => 'Visitor X',
        ]);

        $consent = Consent::where('subject_id', $host->id)->first();
        $this->assertNotNull($consent);
        $this->assertSame(ConsentType::GuestDataOnBehalf, $consent->consent_type);
        $this->assertNotNull($consent->granted_at);
        $this->assertNull($consent->revoked_at);
    }

    public function test_a_member_only_sees_their_own_guests(): void
    {
        $host = User::factory()->create();
        $host->assignRole('member');
        $otherHost = User::factory()->create();

        Guest::factory()->for($host, 'host')->create(['full_name' => 'Mine']);
        Guest::factory()->for($otherHost, 'host')->create(['full_name' => 'Not mine']);

        Sanctum::actingAs($host, ['*']);
        $response = $this->getJson('/api/v1/member/guests');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.full_name', 'Mine');
    }

    public function test_a_member_cannot_delete_another_members_guest(): void
    {
        $host = User::factory()->create();
        $otherMember = User::factory()->create();
        $otherMember->assignRole('member');

        $guest = Guest::factory()->for($host, 'host')->create();

        Sanctum::actingAs($otherMember, ['*']);
        $response = $this->deleteJson("/api/v1/member/guests/{$guest->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('guests', ['id' => $guest->id]);
    }

    public function test_a_member_can_delete_their_own_guest(): void
    {
        $host = User::factory()->create();
        $host->assignRole('member');
        $guest = Guest::factory()->for($host, 'host')->create();

        Sanctum::actingAs($host, ['*']);
        $response = $this->deleteJson("/api/v1/member/guests/{$guest->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('guests', ['id' => $guest->id]);
    }
}
