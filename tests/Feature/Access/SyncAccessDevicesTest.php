<?php

namespace Tests\Feature\Access;

use App\Domain\Foundation\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncAccessDevicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.ttlock.client_id' => 'client']);
    }

    private function fakeAccount(array $gateways, array $locks): void
    {
        Http::fake([
            'api.sciener.test/oauth2/token' => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7776000], 200),
            'api.sciener.test/v3/gateway/list*' => Http::response(['list' => $gateways, 'pages' => 1, 'total' => count($gateways)], 200),
            'api.sciener.test/v3/lock/list*' => Http::response(['list' => $locks, 'pages' => 1, 'total' => count($locks)], 200),
        ]);
    }

    public function test_status_and_battery_follow_the_account(): void
    {
        $this->fakeAccount(
            [['gatewayId' => 2334668, 'isOnline' => 1, 'lockNum' => 1, 'networkName' => 'ADD-Office']],
            [['lockId' => 33804038, 'hasGateway' => 1, 'electricQuantity' => 85, 'electricQuantityUpdateDate' => 1793105585000, 'lockData' => 'secret']],
        );
        $gateway = Device::factory()->create(['type' => 'gateway', 'external_ref' => '2334668']);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '33804038', 'parent_device_id' => $gateway->id]);
        $unknown = Device::factory()->create(['type' => 'lock', 'external_ref' => '999']);

        $this->artisan('access:sync-devices')->assertSuccessful();

        $this->assertSame('online', $gateway->fresh()->status);
        $this->assertSame(1, $gateway->fresh()->metadata['lock_count']);
        $this->assertSame('online', $lock->fresh()->status);
        $this->assertSame(85, $lock->fresh()->metadata['battery']);
        $this->assertTrue($lock->fresh()->metadata['has_gateway']);
        $this->assertArrayNotHasKey('lockData', $lock->fresh()->metadata);
        $this->assertSame('faulted', $unknown->fresh()->status);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/v3/lock/list'));
    }

    public function test_a_lock_with_no_gateway_or_an_offline_one_is_offline(): void
    {
        $this->fakeAccount(
            [['gatewayId' => 2334668, 'isOnline' => 0, 'lockNum' => 0]],
            [
                ['lockId' => 1, 'hasGateway' => 0, 'electricQuantity' => 85],
                ['lockId' => 2, 'hasGateway' => 1, 'electricQuantity' => 40],
            ],
        );
        $gateway = Device::factory()->create(['type' => 'gateway', 'external_ref' => '2334668', 'status' => 'online']);
        $unpaired = Device::factory()->create(['type' => 'lock', 'external_ref' => '1', 'status' => 'online']);
        $behindOffline = Device::factory()->create(['type' => 'lock', 'external_ref' => '2', 'parent_device_id' => $gateway->id, 'status' => 'online']);

        $this->artisan('access:sync-devices')->assertSuccessful();

        $this->assertSame('offline', $gateway->fresh()->status);
        $this->assertSame('offline', $unpaired->fresh()->status);
        $this->assertSame('offline', $behindOffline->fresh()->status);
    }

    public function test_nothing_is_called_without_a_ttlock_account(): void
    {
        config(['services.ttlock.client_id' => null]);
        Device::factory()->create(['type' => 'lock', 'external_ref' => '1']);

        $this->artisan('access:sync-devices')->assertSuccessful();

        Http::assertNothingSent();
    }
}
