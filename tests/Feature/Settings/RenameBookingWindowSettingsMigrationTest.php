<?php

namespace Tests\Feature\Settings;

use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The booking-window settings move to the names the API gives them, keeping
 * an admin's edited value; the length ceiling is dropped.
 */
class RenameBookingWindowSettingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_09_28_100000_rename_booking_window_settings.php');
    }

    private function row(string $key, int $value): void
    {
        DB::table('settings')->insert([
            'key' => $key, 'scope_type' => 'global', 'scope_id' => 0,
            'type' => 'int', 'value' => (string) $value,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_renamed_settings_keep_their_values_and_the_ceiling_is_gone(): void
    {
        $this->row('booking.min_advance_minutes', 45);
        $this->row('booking.max_advance_days', 14);
        $this->row('booking.max_duration_minutes', 180);

        $this->migration()->up();

        $settings = app(SettingService::class);
        $this->assertSame(45, $settings->get('booking.min_notice_minutes'));
        $this->assertSame(14, $settings->get('booking.max_days_ahead'));
        $this->assertSame(0, DB::table('settings')->whereIn('key', [
            'booking.min_advance_minutes', 'booking.max_advance_days', 'booking.max_duration_minutes',
        ])->count());
    }

    public function test_a_row_already_under_the_new_name_wins(): void
    {
        $this->row('booking.min_advance_minutes', 45);
        app(SettingService::class)->set('booking.min_notice_minutes', 60, SettingValueType::Int);

        $this->migration()->up();

        $this->assertSame(1, DB::table('settings')->where('key', 'like', 'booking.min_%notice%')->count());
        $this->assertSame(60, app(SettingService::class)->get('booking.min_notice_minutes'));
        $this->assertFalse(DB::table('settings')->where('key', 'booking.min_advance_minutes')->exists());
    }

    public function test_down_puts_the_old_names_back(): void
    {
        $this->row('booking.min_notice_minutes', 45);
        $this->row('booking.max_days_ahead', 14);

        $this->migration()->down();

        $this->assertSame('45', DB::table('settings')->where('key', 'booking.min_advance_minutes')->value('value'));
        $this->assertSame('14', DB::table('settings')->where('key', 'booking.max_advance_days')->value('value'));
        $this->assertSame('0', DB::table('settings')->where('key', 'booking.max_duration_minutes')->value('value'));
    }
}
