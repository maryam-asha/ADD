<?php

namespace Tests\Unit\Domain\Access\Services;

use App\Domain\Access\Exceptions\TTLockException;
use App\Domain\Access\Services\TTLockClient;
use App\Domain\Foundation\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TTLockClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.ttlock.base_url' => 'https://api.sciener.test']);
    }

    private function fakeToken(): void
    {
        Http::fake([
            'api.sciener.test/oauth2/token' => Http::response([
                'access_token' => 'token-abc', 'refresh_token' => 'refresh-abc',
                'expires_in' => 7776000, 'uid' => 1, 'scope' => 'user,key,room',
            ], 200),
        ]);
    }

    public function test_add_period_passcode_returns_passcode_and_vendor_id(): void
    {
        $this->fakeToken();
        Http::fake([
            'api.sciener.test/v3/keyboardPwd/get' => Http::response(['keyboardPwd' => '135790', 'keyboardPwdId' => 42], 200),
        ]);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '99']);

        $result = app(TTLockClient::class)->addPeriodPasscode($lock, now(), now()->addHours(2));

        $this->assertSame('135790', $result['passcode']);
        $this->assertSame(42, $result['vendor_passcode_id']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.sciener.test/v3/keyboardPwd/get'
            && $request['lockId'] === '99' && $request['keyboardPwdType'] == 3);
    }

    public function test_delete_passcode_sends_gateway_delete_type(): void
    {
        $this->fakeToken();
        Http::fake(['api.sciener.test/v3/keyboardPwd/delete' => Http::response(['errcode' => 0, 'errmsg' => 'none error message'], 200)]);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '99']);

        app(TTLockClient::class)->deletePasscode($lock, 42);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.sciener.test/v3/keyboardPwd/delete'
            && $request['deleteType'] == 2 && $request['keyboardPwdId'] == 42);
    }

    public function test_remote_unlock_success(): void
    {
        $this->fakeToken();
        Http::fake(['api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => 0, 'errmsg' => 'none error message'], 200)]);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '99']);

        app(TTLockClient::class)->remoteUnlock($lock);

        $this->assertTrue(true); // no exception thrown
    }

    public function test_remote_unlock_gateway_offline_maps_to_named_exception(): void
    {
        $this->fakeToken();
        Http::fake(['api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => -2012, 'errmsg' => 'The Lock is not connected to any Gateway.'], 200)]);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '99']);

        try {
            app(TTLockClient::class)->remoteUnlock($lock);
            $this->fail('Expected TTLockException');
        } catch (TTLockException $e) {
            $this->assertSame(-2012, $e->vendorErrorCode);
        }
    }

    public function test_remote_unlock_disabled_maps_to_named_exception(): void
    {
        $this->fakeToken();
        Http::fake(['api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => -4043, 'errmsg' => 'not supported'], 200)]);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '99']);

        $this->expectException(TTLockException::class);
        app(TTLockClient::class)->remoteUnlock($lock);
    }

    public function test_invalid_credentials_on_token_fetch_throws(): void
    {
        Http::fake(['api.sciener.test/oauth2/token' => Http::response(['errcode' => 10007, 'errmsg' => 'invalid account'], 200)]);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '99']);

        $this->expectException(TTLockException::class);
        app(TTLockClient::class)->remoteUnlock($lock);
    }

    public function test_token_is_cached_across_calls(): void
    {
        $this->fakeToken();
        Http::fake(['api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => 0, 'errmsg' => ''], 200)]);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '99']);

        $client = app(TTLockClient::class);
        $client->remoteUnlock($lock);
        $client->remoteUnlock($lock);

        Http::assertSentCount(3); // 1 token fetch + 2 unlocks, not 2 token fetches + 2 unlocks
    }

    public function test_expired_cached_token_is_refreshed_not_re_fetched_from_scratch(): void
    {
        Cache::put('ttlock:oauth_token', [
            'access_token' => 'stale', 'refresh_token' => 'refresh-abc', 'expires_at' => time() - 10,
        ], now()->addDay());
        Http::fake([
            'api.sciener.test/oauth2/token' => Http::response([
                'access_token' => 'fresh', 'refresh_token' => 'refresh-new', 'expires_in' => 7776000,
            ], 200),
            'api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => 0, 'errmsg' => ''], 200),
        ]);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '99']);

        app(TTLockClient::class)->remoteUnlock($lock);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2/token') && ($request['grant_type'] ?? null) === 'refresh_token');
    }
}
