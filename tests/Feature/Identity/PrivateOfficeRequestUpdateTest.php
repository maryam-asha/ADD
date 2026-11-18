<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * `PrivateOfficeRequestController::update()` now returns `{"message": ...}`
 * instead of the full resource (admin surface convention change mirroring
 * the earlier member-surface change) — this is the first HTTP-level
 * coverage of that endpoint.
 */
class PrivateOfficeRequestUpdateTest extends IdentityTestCase
{
    public function test_operations_can_update_a_private_office_request_and_gets_back_a_message(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $poRequest = PrivateOfficeRequest::factory()->create();

        $response = $this->withHeader('lang', 'en')->patchJson("/api/v1/admin/private-office-requests/{$poRequest->id}", [
            'quote_ref' => 'Q-5001',
            'status' => 'quoted',
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Private office request updated.']);

        $this->assertDatabaseHas('private_office_requests', [
            'id' => $poRequest->id,
            'quote_ref' => 'Q-5001',
            'status' => 'quoted',
        ]);
    }
}
