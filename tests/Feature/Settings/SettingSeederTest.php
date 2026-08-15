<?php

namespace Tests\Feature\Settings;

use App\Domain\Settings\Services\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_every_key_required_by_the_2026_08_15_decision_session(): void
    {
        $this->seed(SettingSeeder::class);
        $settings = app(SettingService::class);

        $this->assertSame(60, $settings->get('booking.cancellation_window_minutes'));
        $this->assertSame(30, $settings->get('booking.slot_granularity_minutes'));
        $this->assertSame(60, $settings->get('booking.min_duration_minutes'));
        $this->assertSame(10, $settings->get('booking.overrun_grace_minutes'));
        $this->assertSame(0, $settings->get('booking.buffer_minutes'));
        $this->assertSame(80, $settings->get('profile.completion_threshold'));
        $this->assertSame(120, $settings->get('guest.host_approval_timeout_seconds'));
        $this->assertTrue($settings->get('module.cafe.is_enabled'));
    }
}
