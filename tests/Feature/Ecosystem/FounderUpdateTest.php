<?php

namespace Tests\Feature\Ecosystem;

use App\Domain\Ecosystem\Models\Founder;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `FounderController::update()` now returns `{"message": ...}` instead of the
 * full resource (admin surface convention change mirroring the earlier
 * member-surface change) — this is the first HTTP-level coverage of that
 * endpoint.
 */
class FounderUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_update_a_founder_and_gets_back_a_message(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $founder = Founder::create([
            'name' => ['ar' => 'أحمد الشامي', 'en' => 'Ahmad Al-Shami'],
            'order' => 0,
        ]);

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/founders/{$founder->id}", [
            'name' => ['ar' => 'أحمد الشامي', 'en' => 'Ahmad Shami'],
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Founder updated.']);

        $this->assertSame(['ar' => 'أحمد الشامي', 'en' => 'Ahmad Shami'], $founder->fresh()->name);
    }
}
