<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Enums\ProviderStatus;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_provider_has_accounts_and_client_companies(): void
    {
        $provider = Provider::factory()->create();
        $user = User::factory()->create();
        $provider->users()->attach($user->id);
        $company = Company::factory()->create(['provider_id' => $provider->id]);

        $this->assertSame(ProviderStatus::Active, $provider->fresh()->status);
        $this->assertTrue($provider->companies->contains($company));
        $this->assertTrue($company->provider->is($provider));
        $this->assertTrue(Provider::forUser($user)->is($provider));
    }

    public function test_an_account_works_for_one_provider_only(): void
    {
        $user = User::factory()->create();
        Provider::factory()->create()->users()->attach($user->id);

        $this->expectException(QueryException::class);
        Provider::factory()->create()->users()->attach($user->id);
    }

    public function test_a_request_casts_its_type_status_and_payload(): void
    {
        $request = ProviderRequest::factory()->create([
            'type' => ProviderRequestType::WalletTopUp,
            'payload' => ['points' => 500, 'payer' => 'company'],
        ])->fresh();

        $this->assertSame(ProviderRequestType::WalletTopUp, $request->type);
        $this->assertSame(ProviderRequestStatus::Pending, $request->status);
        $this->assertSame(500, $request->payload['points']);
        $this->assertTrue($request->isPending());
    }
}
