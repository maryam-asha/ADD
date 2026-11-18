<?php

namespace Tests\Guards;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * USD is the base currency (docs/decisions/money-model.md, PRD v0.7.1
 * decision #15) and the system-wide default for `preferred_currency`,
 * enforced at the database column level (not just application code) so no
 * user is ever left with a null preference. Originally shipped seeded to
 * SYP by mistake — docs/superpowers/specs/2026-08-11-currency-header-design.md
 * §3 predates that correction.
 */
class PreferredCurrencyDefaultsToUsdTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_column_default_is_usd(): void
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'No Override',
            'phone' => '+963911111111',
            'password' => 'hashed',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('users', ['id' => $id, 'preferred_currency' => 'USD']);
    }

    public function test_a_factory_created_user_with_no_override_has_usd_on_the_in_memory_model(): void
    {
        $user = User::factory()->create();

        $this->assertSame('USD', $user->preferred_currency);
    }
}
