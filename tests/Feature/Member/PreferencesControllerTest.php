<?php

namespace Tests\Feature\Member;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PreferencesControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_a_member_can_update_their_preferred_currency(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->patchJson('/api/v1/member/preferences/currency', [
            'preferred_currency' => 'USD',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.preferred_currency', 'USD');
        $this->assertDatabaseHas('users', ['id' => $member->id, 'preferred_currency' => 'USD']);
    }

    public function test_preferred_currency_is_rejected_when_not_usd_or_syp(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->patchJson('/api/v1/member/preferences/currency', [
            'preferred_currency' => 'EUR',
        ]);

        $response->assertStatus(422);
    }

    public function test_a_member_can_update_their_preferred_language_post_signup(): void
    {
        $member = User::factory()->create(['preferred_language' => 'ar']);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->patchJson('/api/v1/member/preferences/language', [
            'preferred_language' => 'en',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.preferred_language', 'en');
        $this->assertDatabaseHas('users', ['id' => $member->id, 'preferred_language' => 'en']);
    }

    public function test_preferred_language_is_rejected_when_not_ar_or_en(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->patchJson('/api/v1/member/preferences/language', [
            'preferred_language' => 'fr',
        ]);

        $response->assertStatus(422);
    }
}
