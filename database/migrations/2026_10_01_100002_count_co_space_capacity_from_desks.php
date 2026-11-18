<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A co_space's capacity is the number of its desks from now on, kept by
     * the SeatDesk model (docs/decisions/coworking-seat-pool.md). A co_space
     * with no desks becomes 0 seats — add its desks before deploying.
     */
    public function up(): void
    {
        DB::table('spaces')->where('space_type', 'co_space')->orderBy('id')->each(function (object $space) {
            DB::table('spaces')->where('id', $space->id)->update([
                'capacity' => DB::table('seats_desks')->where('space_id', $space->id)->count(),
            ]);
        });
    }

    /** The typed-in numbers are gone; nothing to restore them from. */
    public function down(): void {}
};
