<?php

namespace Tests\Feature\Settings;

use App\Domain\Finance\Models\Currency;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use App\Support\Time\LocalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-request memo must not outlive the value it caches.
 *
 * SettingService, LocalTime and EventPricePresenter are singletons so that a
 * value read once per rendered row costs one query rather than one per row —
 * CACHE_STORE is `database` in production, so even a cache hit is a query.
 * A memo that survives a write would be worse than the queries it saves: the
 * app would keep serving the old timezone until the process restarted.
 */
class SettingMemoInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_changed_setting_is_visible_immediately(): void
    {
        $settings = app(SettingService::class);

        $settings->set('app.timezone', 'Asia/Damascus', SettingValueType::String);
        $this->assertSame('Asia/Damascus', $settings->get('app.timezone'));

        $settings->set('app.timezone', 'Europe/London', SettingValueType::String);

        $this->assertSame('Europe/London', $settings->get('app.timezone'));
    }

    /** The same instance is shared, so the memo must be cleared, not bypassed. */
    public function test_the_change_reaches_a_collaborator_holding_the_same_instance(): void
    {
        $settings = app(SettingService::class);
        $time = app(LocalTime::class);

        $settings->set('app.timezone', 'Asia/Damascus', SettingValueType::String);
        $this->assertSame('Asia/Damascus', $time->timezone());

        $settings->set('app.timezone', 'Europe/London', SettingValueType::String);

        $this->assertSame('Europe/London', $time->timezone());
    }

    public function test_moving_the_base_currency_is_visible_immediately(): void
    {
        Currency::query()->updateOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'is_base' => true, 'is_active' => true]
        );

        $this->assertSame('USD', Currency::baseCode());

        Currency::query()->where('code', 'USD')->update(['is_base' => false]);
        Currency::query()->updateOrCreate(
            ['code' => 'EUR'],
            ['name' => 'Euro', 'symbol' => '€', 'is_base' => true, 'is_active' => true]
        );

        $this->assertSame('EUR', Currency::baseCode());
    }
}
