<?php

namespace Tests\Feature\Admin;

use App\Domain\Finance\Models\Currency;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Api\V1\Admin\CurrencyController deliberately doesn't extend
 * AdminResourceController — see the class docblock. Covers store/update/
 * updateStatus, `is_base` never being settable, and the base currency
 * being immune to deactivation (docs/decisions/multi-currency-support.md).
 */
class CurrencyControllerTest extends TestCase
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

    public function test_index_lists_all_currencies_including_inactive_ones_ordered_by_order(): void
    {
        $this->actingAsAdmin();
        Currency::factory()->create(['code' => 'EUR', 'order' => 5, 'is_active' => false]);

        $response = $this->getJson('/api/v1/admin/currencies');

        $response->assertOk();
        // SYP (order 1), USD (order 2), EUR (order 5).
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('data.2.code', 'EUR');
    }

    public function test_an_admin_can_create_a_new_currency(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/currencies', [
            'code' => 'EUR',
            'name' => ['en' => 'Euro', 'ar' => 'يورو'],
            'symbol' => '€',
            'decimal_places' => 2,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.code', 'EUR');
        $this->assertDatabaseHas('currencies', ['code' => 'EUR', 'is_base' => false, 'is_active' => true]);
    }

    /**
     * `is_base` is not a field on StoreCurrencyRequest at all — sending it
     * must have no effect, not just be silently validated away.
     */
    public function test_store_never_accepts_is_base_from_the_request(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/currencies', [
            'code' => 'EUR',
            'name' => ['en' => 'Euro', 'ar' => 'يورو'],
            'symbol' => '€',
            'decimal_places' => 2,
            'is_base' => true,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('currencies', ['code' => 'EUR', 'is_base' => false]);
    }

    public function test_creating_a_currency_with_a_duplicate_code_is_rejected(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/currencies', [
            'code' => 'USD',
            'name' => ['en' => 'US Dollar', 'ar' => 'دولار أمريكي'],
            'decimal_places' => 2,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('code');
    }

    public function test_show_returns_a_single_currency(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/currencies/USD');

        $response->assertOk();
        $response->assertJsonPath('data.code', 'USD');
    }

    public function test_an_admin_can_update_a_currencys_name_symbol_decimal_places_and_order(): void
    {
        $this->actingAsAdmin();
        $currency = Currency::factory()->create(['code' => 'EUR']);

        $response = $this->withHeader('lang', 'en')->patchJson("/api/v1/admin/currencies/{$currency->code}", [
            'name' => ['en' => 'Euro', 'ar' => 'يورو'],
            'symbol' => '€',
            'decimal_places' => 2,
            'order' => 9,
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Currency updated.']);
        $this->assertDatabaseHas('currencies', ['code' => 'EUR', 'symbol' => '€', 'order' => 9]);
    }

    /**
     * update() never accepts `code`, `is_base`, or `is_active` — those
     * aren't in UpdateCurrencyRequest's rules at all, so sending them is a
     * no-op rather than a validation error.
     */
    public function test_update_cannot_change_the_code_or_is_base_or_is_active(): void
    {
        $this->actingAsAdmin();
        $currency = Currency::factory()->create(['code' => 'EUR', 'is_active' => true]);

        $this->patchJson("/api/v1/admin/currencies/{$currency->code}", [
            'name' => ['en' => 'Euro', 'ar' => 'يورو'],
            'decimal_places' => 2,
            'code' => 'XXX',
            'is_base' => true,
            'is_active' => false,
        ])->assertOk();

        $this->assertDatabaseHas('currencies', ['code' => 'EUR', 'is_base' => false, 'is_active' => true]);
        $this->assertDatabaseMissing('currencies', ['code' => 'XXX']);
    }

    public function test_an_admin_can_deactivate_a_non_base_currency(): void
    {
        $this->actingAsAdmin();
        $currency = Currency::factory()->create(['code' => 'EUR', 'is_active' => true]);

        $response = $this->withHeader('lang', 'en')->patchJson("/api/v1/admin/currencies/{$currency->code}/status", [
            'is_active' => false,
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Currency status updated.']);
        $this->assertDatabaseHas('currencies', ['code' => 'EUR', 'is_active' => false]);
    }

    /**
     * The base currency (USD) can never be deactivated — conversion and
     * resolution both depend on exactly one always-active base row.
     */
    public function test_the_base_currency_cannot_be_deactivated(): void
    {
        $this->actingAsAdmin();

        $response = $this->withHeader('lang', 'en')->patchJson('/api/v1/admin/currencies/USD/status', [
            'is_active' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'The base currency cannot be deactivated.');
        $this->assertDatabaseHas('currencies', ['code' => 'USD', 'is_active' => true, 'is_base' => true]);
    }

    public function test_an_operations_user_can_also_manage_currencies(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->getJson('/api/v1/admin/currencies')->assertOk();
    }

    public function test_a_member_cannot_manage_currencies(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->postJson('/api/v1/admin/currencies', [
            'code' => 'EUR',
            'name' => ['en' => 'Euro', 'ar' => 'يورو'],
            'decimal_places' => 2,
        ])->assertForbidden();
    }

    public function test_guests_are_rejected_from_admin_currency_routes(): void
    {
        $this->getJson('/api/v1/admin/currencies')->assertUnauthorized();
    }

    public function test_a_new_currency_defaults_to_a_cash_step_of_one(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/currencies', [
            'code' => 'EUR',
            'name' => ['en' => 'Euro', 'ar' => 'يورو'],
            'decimal_places' => 2,
        ])->assertCreated()->assertJsonPath('data.cash_step', '1');

        $this->assertSame('1', Currency::find('EUR')->cash_step);
    }

    public function test_an_admin_can_set_a_currencys_cash_step(): void
    {
        $this->actingAsAdmin();
        $currency = Currency::factory()->create(['code' => 'EUR']);

        $this->patchJson("/api/v1/admin/currencies/{$currency->code}", [
            'name' => ['en' => 'Euro', 'ar' => 'يورو'],
            'decimal_places' => 2,
            'cash_step' => '0.5',
        ])->assertOk();

        $this->assertSame('0.5', $currency->fresh()->cash_step);
        $this->getJson("/api/v1/admin/currencies/{$currency->code}")->assertJsonPath('data.cash_step', '0.5');
    }

    public function test_a_cash_step_must_be_positive_with_at_most_two_decimals(): void
    {
        $this->actingAsAdmin();
        $currency = Currency::factory()->create(['code' => 'EUR']);

        foreach (['0', '-1', '0.005', 'abc'] as $step) {
            $this->patchJson("/api/v1/admin/currencies/{$currency->code}", [
                'name' => ['en' => 'Euro', 'ar' => 'يورو'],
                'decimal_places' => 2,
                'cash_step' => $step,
            ])->assertJsonValidationErrors('cash_step');
        }
    }

    public function test_existing_currencies_get_a_cash_step_of_one(): void
    {
        $this->assertSame('1', Currency::find('SYP')->cash_step);
        $this->assertSame('1', Currency::find('USD')->cash_step);
    }

    /**
     * Amounts in a currency carry no more decimals than it uses
     * (AmountFitsCurrency), so a step finer than that would have the
     * dashboard quote amounts the top-up then refuses — 0.50 for a
     * pound with no decimals.
     */
    public function test_a_cash_step_cannot_be_finer_than_the_currencys_decimals(): void
    {
        $this->actingAsAdmin();
        $currency = Currency::factory()->create(['code' => 'EUR', 'decimal_places' => 2]);
        $patch = fn (array $fields) => $this->withHeader('lang', 'en')->patchJson(
            "/api/v1/admin/currencies/{$currency->code}",
            ['name' => ['en' => 'Euro', 'ar' => 'يورو'], ...$fields],
        );

        $patch(['decimal_places' => 0, 'cash_step' => '0.50'])
            ->assertJsonValidationErrors(['cash_step' => __('api.validation.cash_step_must_be_whole')]);
        $patch(['decimal_places' => 1, 'cash_step' => '0.25'])
            ->assertJsonValidationErrors(['cash_step' => __('api.validation.cash_step_too_precise', ['decimals' => 1])]);

        // Trailing zeros are not precision: 5.00 is a whole 5.
        $patch(['decimal_places' => 0, 'cash_step' => '5'])->assertOk();
        $patch(['decimal_places' => 1, 'cash_step' => '0.5'])->assertOk();
    }

    public function test_dropping_decimals_below_the_stored_cash_step_is_refused(): void
    {
        $this->actingAsAdmin();
        $currency = Currency::factory()->create(['code' => 'EUR', 'decimal_places' => 2, 'cash_step' => '0.50']);

        $this->withHeader('lang', 'en')->patchJson("/api/v1/admin/currencies/{$currency->code}", [
            'name' => ['en' => 'Euro', 'ar' => 'يورو'],
            'decimal_places' => 0,
        ])->assertJsonValidationErrors(['cash_step' => __('api.validation.cash_step_must_be_whole')]);

        $this->assertSame(2, $currency->fresh()->decimal_places);
    }

    public function test_a_new_currency_cannot_have_a_cash_step_finer_than_its_decimals(): void
    {
        $this->actingAsAdmin();
        $currency = fn (string $code, array $fields) => [
            'code' => $code,
            'name' => ['en' => $code, 'ar' => $code],
            ...$fields,
        ];

        $this->withHeader('lang', 'en')
            ->postJson('/api/v1/admin/currencies', $currency('TRY', ['decimal_places' => 0, 'cash_step' => '0.5']))
            ->assertJsonValidationErrors(['cash_step' => __('api.validation.cash_step_must_be_whole')]);

        // No step given: the default of 1 fits any currency.
        $this->postJson('/api/v1/admin/currencies', $currency('TRY', ['decimal_places' => 0]))
            ->assertCreated()
            ->assertJsonPath('data.cash_step', '1');
    }
}
