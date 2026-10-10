<?php

namespace Tests\Concerns;

use App\Domain\Finance\Models\Currency;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Offices, lease request bodies and a fake TTLock, for any test that contracts a lease. */
trait BuildsOfficeLeases
{
    /** An active private office in $branch (a new branch when null), with a lock unless told otherwise. */
    protected function leasableOffice(?Branch $branch = null, bool $withLock = true): Space
    {
        $branch ??= Branch::factory()->create();
        $space = Space::factory()->ofType(SpaceType::Business)->create([
            'building_id' => Building::factory()->create(['branch_id' => $branch->id])->id,
        ]);

        if ($withLock) {
            Device::factory()->create(['branch_id' => $branch->id, 'space_id' => $space->id, 'type' => 'lock', 'external_ref' => '77']);
        }

        return $space;
    }

    /**
     * The lease fields of a contract body: a year, billed quarterly, 500 USD a
     * month, starting tomorrow (so no lock call is made unless a test asks).
     */
    protected function leasePayload(Space $space, array $overrides = []): array
    {
        if (! Currency::query()->where('code', 'USD')->exists()) {
            $this->seed(CurrencySeeder::class);
        }

        return [
            'space_id' => $space->id,
            'starts_on' => now()->addDay()->toDateString(),
            'duration_months' => 12,
            'billing_cycle_months' => 3,
            'monthly_amount' => '500.00',
            'currency' => 'USD',
            ...$overrides,
        ];
    }

    /**
     * POST admin/companies contracts a lease, so it needs office-leases.create
     * on top of the role (docs/decisions/office-leases.md): grant it to $role.
     */
    protected function grantLeaseCreation(string $role = 'operations'): void
    {
        if (! Permission::query()->where('name', 'office-leases.create')->exists()) {
            $this->seed(PermissionSeeder::class);
        }

        Role::findByName($role, 'web')->givePermissionTo('office-leases.create');
    }

    /** TTLock answering every call: passcode 112233 / id 5 on creation, success on delete. */
    protected function fakeTtlock(): void
    {
        Http::preventStrayRequests();
        config(['services.ttlock.base_url' => 'https://api.sciener.test']);
        Http::fake([
            'api.sciener.test/oauth2/token' => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7776000], 200),
            'api.sciener.test/v3/keyboardPwd/get' => Http::response(['keyboardPwd' => '112233', 'keyboardPwdId' => 5], 200),
            'api.sciener.test/v3/keyboardPwd/delete' => Http::response(['errcode' => 0, 'errmsg' => ''], 200),
        ]);
    }
}
