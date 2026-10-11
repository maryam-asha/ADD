<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\Kiosk;
use App\Domain\Foundation\Models\Space;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class KioskAdminTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_an_admin_can_create_a_kiosk_on_a_coworking_space(): void
    {
        $this->actingAsAdmin();
        $space = $this->coworkingSpace();

        $this->postJson('/api/v1/admin/kiosks', ['name' => 'Main entrance', 'space_id' => $space->id])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Main entrance')
            ->assertJsonPath('data.space_id', $space->id)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_paired', false);
    }

    public function test_a_kiosk_cannot_serve_a_room(): void
    {
        $this->actingAsAdmin();
        $room = Space::factory()->room()->create();

        $this->withHeader('lang', 'en')
            ->postJson('/api/v1/admin/kiosks', ['name' => 'Lobby', 'space_id' => $room->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('space_id');
        $this->assertSame(0, Kiosk::count());
    }

    public function test_an_admin_can_update_a_kiosk(): void
    {
        $this->actingAsAdmin();
        $kiosk = Kiosk::factory()->create();

        $this->withHeader('lang', 'en')
            ->patchJson("/api/v1/admin/kiosks/{$kiosk->id}", ['is_active' => false])
            ->assertOk()
            ->assertExactJson(['message' => 'Kiosk updated.']);
        $this->assertFalse($kiosk->fresh()->is_active);
    }

    public function test_operations_cannot_manage_kiosks(): void
    {
        $this->actingAsOperations();

        $this->postJson('/api/v1/admin/kiosks', ['name' => 'X', 'space_id' => $this->coworkingSpace()->id])
            ->assertForbidden();
    }

    public function test_no_listing_exposes_the_secret_or_the_pairing_code_hash(): void
    {
        $this->actingAsAdmin();
        $kiosk = Kiosk::factory()->create();
        $kiosk->forceFill([
            'totp_secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
            'pairing_code_hash' => str_repeat('a', 64),
            'paired_at' => now(),
        ])->save();

        $this->getJson('/api/v1/admin/kiosks')
            ->assertOk()
            ->assertJsonPath('data.0.is_paired', true)
            ->assertJsonMissingPath('data.0.totp_secret')
            ->assertJsonMissingPath('data.0.pairing_code_hash');

        $body = $this->getJson("/api/v1/admin/kiosks/{$kiosk->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $body);
        $this->assertStringNotContainsString(str_repeat('a', 64), $body);
    }
}
