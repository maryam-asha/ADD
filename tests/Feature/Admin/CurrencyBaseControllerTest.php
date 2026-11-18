<?php

namespace Tests\Feature\Admin;

use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PATCH currencies/{currency}/base — reassigns the base currency.
 * docs/decisions/multi-currency-support.md §Addendum 2026-08-31.
 */
class CurrencyBaseControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function actingAsAdmin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
    }

    public function test_happy_path_base_moves_to_new_currency(): void
    {
        $this->actingAsAdmin();
        $eur = Currency::factory()->create(['code' => 'EUR', 'is_active' => true, 'is_base' => false]);

        $response = $this->withHeader('lang', 'en')
            ->patchJson("/api/v1/admin/currencies/{$eur->code}/base");

        $response->assertOk();
        $response->assertExactJson(['message' => 'Base currency updated.']);

        $this->assertDatabaseHas('currencies', ['code' => 'EUR', 'is_base' => true]);
        $this->assertDatabaseHas('currencies', ['code' => 'USD', 'is_base' => false]);
    }

    public function test_exactly_one_base_row_after_reassignment(): void
    {
        $this->actingAsAdmin();
        $eur = Currency::factory()->create(['code' => 'EUR', 'is_active' => true, 'is_base' => false]);

        $this->patchJson("/api/v1/admin/currencies/{$eur->code}/base")->assertOk();

        $this->assertSame(1, Currency::where('is_base', true)->count());
    }

    public function test_422_when_currency_is_already_base(): void
    {
        $this->actingAsAdmin();

        $response = $this->withHeader('lang', 'en')
            ->patchJson('/api/v1/admin/currencies/USD/base');

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'This currency is already the base currency.');
        $this->assertDatabaseHas('currencies', ['code' => 'USD', 'is_base' => true]);
    }

    public function test_422_when_currency_is_inactive(): void
    {
        $this->actingAsAdmin();
        $eur = Currency::factory()->create(['code' => 'EUR', 'is_active' => false, 'is_base' => false]);

        $response = $this->withHeader('lang', 'en')
            ->patchJson("/api/v1/admin/currencies/{$eur->code}/base");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'An inactive currency cannot be set as the base currency.');
        $this->assertDatabaseHas('currencies', ['code' => 'USD', 'is_base' => true]);
    }

    public function test_422_when_exchange_rate_history_exists(): void
    {
        $this->actingAsAdmin();
        $eur = Currency::factory()->create(['code' => 'EUR', 'is_active' => true, 'is_base' => false]);
        ExchangeRate::factory()->create(['currency_code' => 'EUR']);

        $response = $this->withHeader('lang', 'en')
            ->patchJson("/api/v1/admin/currencies/{$eur->code}/base");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Cannot reassign the base currency while exchange rate history exists.');
        $this->assertDatabaseHas('currencies', ['code' => 'USD', 'is_base' => true]);
    }

    public function test_guests_are_rejected(): void
    {
        $eur = Currency::factory()->create(['code' => 'EUR', 'is_active' => true, 'is_base' => false]);

        $this->patchJson("/api/v1/admin/currencies/{$eur->code}/base")->assertUnauthorized();
    }

    public function test_members_are_forbidden(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $eur = Currency::factory()->create(['code' => 'EUR', 'is_active' => true, 'is_base' => false]);

        $this->patchJson("/api/v1/admin/currencies/{$eur->code}/base")->assertForbidden();
    }
}
