<?php

namespace Tests\Feature\Admin;

use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000680272',
            'effective_from' => now()->toISOString(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('exchange_rates', [
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000680272',
            'set_by' => $admin->id,
        ]);
    }

    public function test_the_base_currency_is_rejected_as_a_rate_target(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/exchange-rates', [
            'currency_code' => 'USD',
            'rate_to_base' => '1.0000',
            'effective_from' => now()->toISOString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('currency_code');
    }

    public function test_set_by_is_always_the_authenticated_admin_not_client_supplied(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $otherUser = User::factory()->create();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/admin/exchange-rates', [
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000680272',
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
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000680272',
            'effective_from' => now()->toISOString(),
        ])->assertCreated();

        $activity = Activity::where('description', 'exchange_rate_created')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('SYP', $activity->properties['currency_code']);
        $this->assertSame('0.0000680272', $activity->properties['rate_to_base']);
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

    public function test_accepting_a_suggestion_creates_an_externally_accepted_rate_and_marks_it_accepted(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $suggestion = ExchangeRateSuggestion::factory()->create();

        $response = $this->postJson('/api/v1/admin/exchange-rates', [
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000668',
            'effective_from' => now()->toISOString(),
            'suggestion_id' => $suggestion->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('exchange_rates', [
            'currency_code' => 'SYP',
            'source' => 'external_accepted',
            'suggestion_id' => $suggestion->id,
        ]);
        $this->assertDatabaseHas('exchange_rate_suggestions', [
            'id' => $suggestion->id,
            'status' => 'accepted',
        ]);
        $rate = ExchangeRate::where('suggestion_id', $suggestion->id)->sole();
        $this->assertTrue($suggestion->refresh()->acceptedRate->is($rate));
    }

    public function test_accepting_with_a_rate_modified_from_the_suggestion_still_records_both_numbers_in_the_audit_log(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $suggestion = ExchangeRateSuggestion::factory()->create(['rate_usd_to_syp' => '13275.0000000000']);

        $this->postJson('/api/v1/admin/exchange-rates', [
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000700', // admin edited the number before submitting
            'effective_from' => now()->toISOString(),
            'suggestion_id' => $suggestion->id,
        ])->assertCreated();

        $activity = Activity::where('description', 'exchange_rate_created')->latest('id')->first();
        // Not '0.0000700': the audit log reads $rate->rate_to_base off the
        // Eloquent model, which casts the column as decimal:10 and always
        // pads to exactly 10 decimal places — the same established pattern
        // the pre-existing (unmodified) audit-log test relies on, just not
        // visible there because that test's fixture already had 10 digits.
        $this->assertSame('0.0000700000', $activity->properties['rate_to_base']);
        $this->assertSame('13275.0000000000', $activity->properties['suggested_rate_usd_to_syp']);
    }

    public function test_accepting_a_suggestion_with_an_uninverted_rate_to_base_returns_422(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $suggestion = ExchangeRateSuggestion::factory()->create(['rate_usd_to_syp' => '13275.0000000000']);

        $this->postJson('/api/v1/admin/exchange-rates', [
            'currency_code' => 'SYP',
            'rate_to_base' => '13275', // the raw suggestion number, not inverted
            'effective_from' => now()->toISOString(),
            'suggestion_id' => $suggestion->id,
        ])->assertStatus(422)->assertJsonValidationErrors('rate_to_base');
    }

    public function test_accepting_a_non_pending_suggestion_returns_422(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $suggestion = ExchangeRateSuggestion::factory()->create(['status' => 'dismissed']);

        $this->postJson('/api/v1/admin/exchange-rates', [
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000680272',
            'effective_from' => now()->toISOString(),
            'suggestion_id' => $suggestion->id,
        ])->assertStatus(422);
    }

    public function test_accepting_a_suggestion_against_a_different_valid_currency_code_returns_422(): void
    {
        // EUR isn't seeded by default (only SYP/USD are — see the decision
        // doc's Phase 0 recon), so it's created inline here to prove the
        // *new* currency-mismatch rule fires, not just the pre-existing
        // currency_code exists-check that a genuinely-unknown code would
        // trip anyway.
        Currency::create([
            'code' => 'EUR', 'name' => ['en' => 'Euro', 'ar' => 'يورو'], 'symbol' => '€',
            'decimal_places' => 2, 'is_base' => false, 'is_active' => true, 'order' => 3,
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $suggestion = ExchangeRateSuggestion::factory()->create();

        $this->postJson('/api/v1/admin/exchange-rates', [
            'currency_code' => 'EUR',
            'rate_to_base' => '0.00006',
            'effective_from' => now()->toISOString(),
            'suggestion_id' => $suggestion->id,
        ])->assertStatus(422)->assertJsonValidationErrors('currency_code');
    }

    public function test_a_suggestion_accepted_between_validation_and_the_lock_is_rejected_with_no_new_row(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $suggestion = ExchangeRateSuggestion::factory()->create();

        // Injects the race at the exact moment the controller's own
        // DB::transaction() begins — after StoreExchangeRateRequest's
        // validation has already passed against the still-pending row, but
        // before the controller's lockForUpdate() query runs. Not a claim
        // of real multi-connection concurrency (see the fix's report for
        // why that isn't reproducible against this suite's in-memory
        // SQLite — the same limitation WalkInCapacityServiceTest.php
        // already documents) — a direct, honest injection at the one point
        // that matters: does the controller's own lock-and-recheck catch a
        // status change that happened after validation, rather than
        // trusting a stale read?
        $fired = false;
        Event::listen(TransactionBeginning::class, function () use (&$fired, $suggestion) {
            if (! $fired) {
                $fired = true;
                DB::table('exchange_rate_suggestions')->where('id', $suggestion->id)->update(['status' => 'accepted']);
            }
        });

        $response = $this->postJson('/api/v1/admin/exchange-rates', [
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000680272',
            'effective_from' => now()->toISOString(),
            'suggestion_id' => $suggestion->id,
        ]);

        $this->assertTrue($fired, 'The race-injection listener never fired — this test would pass vacuously without it.');
        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => __('api.admin.exchange_rate_suggestion_not_pending')]);
        $this->assertDatabaseCount('exchange_rates', 0);
    }

    public function test_manual_creation_without_a_suggestion_id_is_unchanged(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/admin/exchange-rates', [
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000680272',
            'effective_from' => now()->toISOString(),
        ])->assertCreated();

        $this->assertDatabaseHas('exchange_rates', ['currency_code' => 'SYP', 'source' => 'manual', 'suggestion_id' => null]);
    }
}
