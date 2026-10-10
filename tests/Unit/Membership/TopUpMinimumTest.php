<?php

namespace Tests\Unit\Membership;

use App\Domain\Membership\Services\TopUpMinimum;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopUpMinimumTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_defaults_to_ten_points_before_the_setting_exists(): void
    {
        $this->assertSame(10, app(TopUpMinimum::class)->points());
    }

    public function test_it_follows_the_setting(): void
    {
        app(SettingService::class)->set(TopUpMinimum::SETTING_KEY, 25, SettingValueType::Int);

        $this->assertSame(25, app(TopUpMinimum::class)->points());
    }

    /** The settings page can only edit a row that exists, so the seeder must create it. */
    public function test_the_setting_seeder_creates_the_row_the_settings_page_edits(): void
    {
        $this->seed(SettingSeeder::class);

        $this->assertSame(10, app(SettingService::class)->get(TopUpMinimum::SETTING_KEY));
    }
}
