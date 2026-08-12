<?php

namespace Tests\Feature\Ecosystem;

use App\Domain\Ecosystem\Models\Partner;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `PartnerController::update()` now returns `{"message": ...}` instead of the
 * full resource (admin surface convention change mirroring the earlier
 * member-surface change) — this is the first HTTP-level coverage of that
 * endpoint.
 */
class PartnerUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_update_a_partner_and_gets_back_a_message(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $partner = Partner::create([
            'name' => ['ar' => 'بنك سوريا', 'en' => 'Bank of Syria'],
            'category' => 'local',
            'order' => 0,
            'is_active' => true,
        ]);

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/partners/{$partner->id}", [
            'name' => ['ar' => 'بنك سوريا', 'en' => 'Bank of Syria'],
            'category' => 'global',
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Partner updated.']);

        $this->assertSame('global', $partner->fresh()->category);
    }
}
