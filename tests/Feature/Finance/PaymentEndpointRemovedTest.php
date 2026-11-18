<?php

namespace Tests\Feature\Finance;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Each operation pays through its own endpoint; the generic one is gone
 * (docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md).
 * What remains is reading: the member's payments, and one payment's status
 * for the app to poll after its WebView.
 */
class PaymentEndpointRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_generic_pay_endpoint_is_gone_and_the_reads_remain(): void
    {
        $this->seed(RoleSeeder::class);
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->postJson('/api/v1/member/payments', ['purpose' => 'wallet_top_up', 'amount' => '10'])
            ->assertStatus(405);

        $this->getJson('/api/v1/member/payments')->assertOk();
    }
}
