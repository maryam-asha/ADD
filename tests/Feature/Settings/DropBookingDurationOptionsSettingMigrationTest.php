<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The duration ladder has no reader since both pickers take a run of cells,
 * so its row goes; down() restores the seeded ladder.
 */
class DropBookingDurationOptionsSettingMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_09_28_100001_drop_booking_duration_options_setting.php');
    }

    public function test_the_ladder_row_is_deleted_and_down_restores_the_seeded_one(): void
    {
        DB::table('settings')->insert([
            'key' => 'booking.duration_options_minutes', 'scope_type' => 'global', 'scope_id' => 0,
            'type' => 'json', 'value' => '[60,120]', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->migration()->up();
        $this->assertFalse(DB::table('settings')->where('key', 'booking.duration_options_minutes')->exists());

        $this->migration()->down();
        $this->assertSame('[60,90,120,180]', DB::table('settings')->where('key', 'booking.duration_options_minutes')->value('value'));
    }
}
