<?php

namespace Tests\Feature\Providers;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Providers\Models\Provider;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class ProviderTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    /** A dashboard account of this provider (a new one when none is given), signed in. */
    protected function providerAccount(?Provider $provider = null): User
    {
        $provider ??= Provider::factory()->create();
        $user = User::factory()->create();
        $user->assignRole('provider');
        $provider->users()->attach($user->id);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /** A client company of this provider, with the wallet CompanyController::store gives every company. */
    protected function clientCompany(Provider $provider, array $attributes = []): Company
    {
        $company = Company::factory()->create(['provider_id' => $provider->id, ...$attributes]);
        Wallet::firstOrCreate(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        return $company;
    }

    protected function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    /** The default branch with a co-working pool of this many seats. */
    protected function branchWithSeats(int $seats): Branch
    {
        Cache::forget('default_branch_id');
        $branch = Branch::factory()->create(['is_default' => true]);
        Space::factory()->create([
            'building_id' => Building::factory()->create(['branch_id' => $branch->id])->id,
            'capacity' => $seats,
        ]);

        return $branch;
    }
}
