<?php

namespace Tests\Feature\Membership;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Services\Otp\OtpProvider;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use stdClass;
use Tests\TestCase;

/**
 * docs/decisions/phase-3-membership-plan-wallet-mechanics.md: company
 * creation and first-time member OTP verification each provision exactly
 * one Wallet row, inline in their existing transaction/branch — no separate
 * creation path.
 */
class WalletProvisioningTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_first_otp_verification_provisions_exactly_one_wallet_and_a_second_login_does_not_add_another(): void
    {
        $captured = new stdClass;

        $this->app->bind(OtpProvider::class, fn () => new class($captured) implements OtpProvider
        {
            public function __construct(private stdClass $captured) {}

            public function send(string $phone, string $code, string $provider): bool
            {
                $this->captured->code = $code;

                return true;
            }
        });

        $phone = '0912345678';

        $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone])->assertOk();

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $phone,
            'code' => $captured->code,
        ]);

        $response->assertOk();

        $user = User::where('phone', $phone)->first();

        $this->assertSame(
            1,
            Wallet::where('owner_type', OwnerType::User)->where('owner_id', $user->id)->count()
        );

        // Second login for the same phone number — not a new user, no new wallet.
        // Travel past the resend cooldown so the second request isn't throttled.
        $this->travel(config('otp.resend_cooldown_seconds') + 1)->seconds();

        $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $phone,
            'code' => $captured->code,
        ])->assertOk();

        $this->assertSame(
            1,
            Wallet::where('owner_type', OwnerType::User)->where('owner_id', $user->id)->count()
        );
    }
}
