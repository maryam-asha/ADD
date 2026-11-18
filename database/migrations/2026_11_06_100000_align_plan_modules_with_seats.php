<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A plan's module now follows its seat (PlanModule::slugForSeat()): flexible
 * is the flexi-desk module, dedicated the dedicated-desk one, no seat none.
 * Existing plans are aligned once. One-way: the modules staff had picked by
 * hand are not kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        $modules = DB::table('plan_modules')->whereIn('slug', ['flexi_desk', 'dedicated_desk'])->pluck('id', 'slug');

        foreach (['flexible' => 'flexi_desk', 'dedicated' => 'dedicated_desk'] as $seat => $slug) {
            DB::table('plans')->where('coworking_seat', $seat)->update(['plan_module_id' => $modules[$slug] ?? null]);
        }

        DB::table('plans')->where('coworking_seat', 'none')->update(['plan_module_id' => null]);
    }

    public function down(): void
    {
        // One-way: the hand-picked modules are gone.
    }
};
