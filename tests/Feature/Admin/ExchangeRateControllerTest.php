<?php

namespace Tests\Feature\Admin;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ExchangeRateControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_an_admin_can_create_a_new_exchange_rate(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/exchange-rates', [
            'rate_usd_to_syp' => '14700.5000',
            'effective_from' => now()->toISOString(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('exchange_rates', [
            'rate_usd_to_syp' => '14700.5000',
            'set_by' => $admin->id,
        ]);
    }

    public function test_set_by_is_always_the_authenticated_admin_not_client_supplied(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $otherUser = User::factory()->create();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/admin/exchange-rates', [
            'rate_usd_to_syp' => '14700.0000',
            'effective_from' => now()->toISOString(),
            'set_by' => $otherUser->id,
        ])->assertCreated();

        $this->assertDatabaseHas('exchange_rates', ['set_by' => $admin->id]);
        $this->assertDatabaseMissing('exchange_rates', ['set_by' => $otherUser->id]);
    }

    public function test_creating_a_rate_writes_an_audit_log_entry(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/admin/exchange-rates', [
            'rate_usd_to_syp' => '14700.0000',
            'effective_from' => now()->toISOString(),
        ])->assertCreated();

        $activity = Activity::where('description', 'exchange_rate_created')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('14700.0000', $activity->properties['rate_usd_to_syp']);
    }

    public function test_index_returns_rates_ordered_by_effective_from_descending(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $older = ExchangeRate::factory()->create(['effective_from' => now()->subDays(10)]);
        $newer = ExchangeRate::factory()->create(['effective_from' => now()->subDay()]);

        $response = $this->getJson('/api/v1/admin/exchange-rates');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $newer->id);
        $response->assertJsonPath('data.1.id', $older->id);
    }

    public function test_an_operations_user_can_also_manage_exchange_rates(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->getJson('/api/v1/admin/exchange-rates')->assertOk();
    }
}
