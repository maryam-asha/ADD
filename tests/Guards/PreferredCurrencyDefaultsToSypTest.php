<?php

namespace Tests\Guards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * docs/superpowers/specs/2026-08-11-currency-header-design.md §3: SYP is
 * the system-wide default for `preferred_currency`, enforced at the
 * database column level (not just application code) so no user is ever
 * left with a null preference.
 */
class PreferredCurrencyDefaultsToSypTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_column_default_is_syp(): void
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'No Override',
            'phone' => '0911111111',
            'password' => 'hashed',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('users', ['id' => $id, 'preferred_currency' => 'SYP']);
    }

    public function test_a_factory_created_user_with_no_override_has_syp_on_the_in_memory_model(): void
    {
        $user = \App\Domain\Identity\Models\User::factory()->create();

        $this->assertSame('SYP', $user->preferred_currency);
    }
}
