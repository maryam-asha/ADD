<?php

namespace Tests\Unit\Domain\Settings;

use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SettingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_returns_the_given_default_when_the_key_does_not_exist(): void
    {
        $service = new SettingService;

        $this->assertNull($service->get('test.missing_key'));
        $this->assertSame('fallback', $service->get('test.missing_key', 'fallback'));
    }

    public function test_set_creates_a_new_global_setting_and_get_reads_it_back(): void
    {
        $service = new SettingService;

        $service->set('test.new_key', 42, SettingValueType::Int);

        $this->assertSame(42, $service->get('test.new_key'));
        $this->assertDatabaseHas('settings', [
            'key' => 'test.new_key',
            'scope_type' => 'global',
            'scope_id' => 0,
            'type' => 'int',
            'value' => '42',
        ]);
    }

    public function test_set_throws_when_creating_a_new_key_without_a_type(): void
    {
        $service = new SettingService;

        $this->expectException(InvalidArgumentException::class);

        $service->set('test.brand_new_key', 42);
    }

    public function test_set_reuses_the_existing_type_when_not_given(): void
    {
        $service = new SettingService;
        $service->set('test.reuse_type', 10, SettingValueType::Int);

        $service->set('test.reuse_type', 20);

        $this->assertSame(20, $service->get('test.reuse_type'));
    }

    public function test_get_caches_the_resolved_value_until_set_invalidates_it(): void
    {
        $service = new SettingService;
        $service->set('test.cached_key', 5, SettingValueType::Int);

        $this->assertSame(5, $service->get('test.cached_key'));

        // Bypasses SettingService::set() on purpose — a direct DB write
        // must not appear until the cache is invalidated.
        Setting::query()->where('key', 'test.cached_key')->update(['value' => '999']);

        $this->assertSame(5, $service->get('test.cached_key'));

        $service->set('test.cached_key', 999, SettingValueType::Int);

        $this->assertSame(999, $service->get('test.cached_key'));
    }
}
