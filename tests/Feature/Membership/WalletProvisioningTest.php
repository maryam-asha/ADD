<?php

namespace Tests\Feature\Membership;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOtp;
use Tests\TestCase;

/**
 * docs/decisions/phase-3-membership-plan-wallet-mechanics.md: company
 * creation and member registration each provision exactly one Wallet row,
 * inline in their existing transaction/branch — no separate creation path.
 */
class WalletProvisioningTest extends TestCase
{
    use InteractsWithOtp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_creating_a_company_provisions_exactly_one_wallet(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $poRequest = PrivateOfficeRequest::factory()->quoted()->create();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/companies', [
            'private_office_request_id' => $poRequest->id,
            'legal_name' => 'ACME LLC',
            'contract_ref' => 'C-1001',
            'branch_id' => $branch->id,
        ]);

        $response->assertCreated();

        $companyId = $response->json('data.id');

        $this->assertSame(
            1,
            Wallet::where('owner_type', OwnerType::Company)->where('owner_id', $companyId)->count()
        );
    }

    /**
     * The "second time round" half of this used to be a repeat login through
     * the same endpoint. Since the hybrid-auth switch, sign-up refuses a number
     * that already has an account, so the invariant is now checked against a
     * refused re-run: the wallet count stays exactly one either way.
     */
    public function test_registration_provisions_exactly_one_wallet_and_a_repeat_attempt_adds_none(): void
    {
        $this->fakeOtpProvider();

        $phone = '+963912345678';

        $payload = [
            'phone' => $phone,
            'name' => 'Maryam Asha',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
        ];

        $code = $this->startRegistration($payload);

        $this->postJson('/api/v1/auth/register/verify', $payload + ['code' => $code])->assertOk();

        $user = User::where('phone', $phone)->sole();

        $this->assertSame(
            1,
            Wallet::where('owner_type', OwnerType::User)->where('owner_id', $user->id)->count()
        );

        // Travel past the resend cooldown so the second request isn't throttled.
        $this->travel(config('otp.resend_cooldown_seconds') + 1)->seconds();

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertSame(
            1,
            Wallet::where('owner_type', OwnerType::User)->where('owner_id', $user->id)->count()
        );
    }
}
