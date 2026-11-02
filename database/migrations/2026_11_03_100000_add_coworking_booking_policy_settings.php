<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The coworking booking policy's two settings (CoworkingBookingPolicy),
     * as rows so the dashboard can edit them. A row a seeder already made
     * is left as it is.
     */
    private const DEFAULTS = [
        'coworking.no_show_release_percent' => ['int', '25'],
        'coworking.late_arrival_billing' => ['string', 'arrival'],
    ];

    public function up(): void
    {
        foreach (self::DEFAULTS as $key => [$type, $value]) {
            if (DB::table('settings')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('settings')->insert([
                'key' => $key,
                'scope_type' => 'global',
                'scope_id' => 0,
                'type' => $type,
                'value' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_keys(self::DEFAULTS))->delete();
    }
};
