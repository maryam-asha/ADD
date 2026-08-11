<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The generic exception-handler branches in bootstrap/app.php need the same
 * translated-by-locale treatment as the messages controllers build by hand.
 */
class ErrorResponseLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_validation_errors_return_a_translated_top_level_message(): void
    {
        $en = $this->withHeader('lang', 'en')->postJson('/api/v1/auth/register', []);
        $ar = $this->withHeader('lang', 'ar')->postJson('/api/v1/auth/register', []);

        $en->assertStatus(422);
        $en->assertJsonPath('message', 'The given data is invalid.');
        $en->assertJsonStructure(['message', 'errors']);

        $ar->assertStatus(422);
        $ar->assertJsonPath('message', 'البيانات المُرسلة غير صالحة.');
    }

    public function test_route_level_throttling_returns_a_translated_message(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'bogus'])->assertStatus(401);
        }

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/auth/refresh', ['refresh_token' => 'bogus']);

        $response->assertStatus(429);
        $response->assertJsonPath('message', 'Too many attempts. Please wait before trying again.');
    }

    public function test_an_unauthenticated_request_to_a_protected_route_returns_a_translated_message(): void
    {
        $en = $this->withHeader('lang', 'en')->getJson('/api/v1/auth/me');
        $ar = $this->withHeader('lang', 'ar')->getJson('/api/v1/auth/me');

        $en->assertStatus(401);
        $en->assertJsonPath('message', 'Unauthenticated.');

        $ar->assertStatus(401);
        $ar->assertJsonPath('message', 'غير مصادَق.');
    }

    public function test_a_policy_denial_returns_a_translated_message(): void
    {
        $company = Company::factory()->create();
        $wallet = Wallet::create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        (new WalletService)->creditGeneral($wallet, '100.00', WalletTransactionSource::TopUp);

        $member = User::factory()->create();
        $member->assignRole('member');
        $company->members()->attach($member->id, ['is_admin' => false]);

        Sanctum::actingAs($member, ['*']);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/member/companies/{$company->id}/wallet-allocations", [
            'category' => 'cafe',
            'amount' => '20.00',
            'user_ids' => [$member->id],
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'This action is unauthorized.');
    }

    public function test_an_unknown_route_returns_a_translated_not_found_message(): void
    {
        $en = $this->withHeader('lang', 'en')->getJson('/api/v1/this-route-does-not-exist');
        $ar = $this->withHeader('lang', 'ar')->getJson('/api/v1/this-route-does-not-exist');

        $en->assertStatus(404);
        $en->assertJsonPath('message', 'The requested resource was not found.');

        $ar->assertStatus(404);
        $ar->assertJsonPath('message', 'المورد المطلوب غير موجود.');
    }
}
