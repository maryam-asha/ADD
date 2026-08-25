<?php

namespace Tests\Feature\Admin;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExchangeRateSuggestionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_it_returns_null_fields_when_no_pending_suggestion_exists(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/exchange-rates/suggestion');

        $response->assertOk();
        $response->assertJson([
            'id' => null,
            'rate_usd_to_syp' => null,
            'source' => null,
            'fetched_at' => null,
            'deviation_percent' => null,
            'source_stale' => true,
            'last_successful_fetch_at' => null,
        ]);
    }

    public function test_it_returns_the_latest_pending_suggestion(): void
    {
        $this->actingAsAdmin();
        ExchangeRateSuggestion::factory()->create(['status' => 'superseded', 'fetched_at' => now()->subDay()]);
        $pending = ExchangeRateSuggestion::factory()->create(['rate_usd_to_syp' => '13275.0000000000', 'fetched_at' => now()]);

        $response = $this->getJson('/api/v1/admin/exchange-rates/suggestion');

        $response->assertOk();
        $response->assertJsonPath('id', $pending->id);
        $response->assertJsonPath('rate_usd_to_syp', '13275.0000000000');
        $response->assertJsonPath('source', 'sp_today');
    }

    public function test_deviation_percent_is_null_without_a_current_effective_rate(): void
    {
        $this->actingAsAdmin();
        ExchangeRateSuggestion::factory()->create();

        $response = $this->getJson('/api/v1/admin/exchange-rates/suggestion');

        $response->assertJsonPath('deviation_percent', null);
    }

    public function test_deviation_percent_compares_both_numbers_in_the_same_direction(): void
    {
        $this->actingAsAdmin();
        // Current effective rate: 1 SYP = 0.0000680272 USD  <=>  1 USD ≈ 14700 SYP.
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272', 'effective_from' => now()->subDay()]);
        // Suggestion: 1 USD = 14994 SYP — roughly 2% higher than 14700.
        ExchangeRateSuggestion::factory()->create(['rate_usd_to_syp' => '14994.0000000000']);

        $response = $this->getJson('/api/v1/admin/exchange-rates/suggestion');

        $response->assertOk();
        $deviation = $response->json('deviation_percent');
        $this->assertGreaterThan(1.5, $deviation);
        $this->assertLessThan(2.5, $deviation);
    }

    public function test_source_stale_is_false_within_48_hours_of_the_last_successful_fetch(): void
    {
        $this->actingAsAdmin();
        ExchangeRateSuggestion::factory()->create(['status' => 'superseded', 'fetched_at' => now()->subHours(10)]);

        $response = $this->getJson('/api/v1/admin/exchange-rates/suggestion');

        $response->assertJsonPath('source_stale', false);
        $this->assertNotNull($response->json('last_successful_fetch_at'));
    }

    public function test_source_stale_flips_true_after_48_hours(): void
    {
        $this->actingAsAdmin();
        ExchangeRateSuggestion::factory()->create(['status' => 'superseded', 'fetched_at' => now()->subHours(49)]);

        $response = $this->getJson('/api/v1/admin/exchange-rates/suggestion');

        $response->assertJsonPath('source_stale', true);
    }

    public function test_the_route_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/exchange-rates/suggestion')->assertUnauthorized();
    }

    public function test_a_member_role_is_rejected(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/exchange-rates/suggestion')->assertForbidden();
    }
}
