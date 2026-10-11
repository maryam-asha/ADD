<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A booking checked out before a checkout wrote `completed` is still
     * `confirmed`, and bookings:close-elapsed only ever looks at bookings
     * nobody checked into — so this one-off pass is what gives it its outcome
     * (docs/decisions/booking-outcomes-and-extension.md). The scheduled
     * command handles the rest: undecided requests and no-shows.
     */
    public function up(): void
    {
        DB::table('bookings')
            ->where('status', 'confirmed')
            ->whereNotNull('checked_out_at')
            ->update(['status' => 'completed']);
    }

    /**
     * The code before this change has no `completed`: every completed
     * booking is a checked-out confirmed one to it.
     */
    public function down(): void
    {
        DB::table('bookings')
            ->where('status', 'completed')
            ->update(['status' => 'confirmed']);
    }
};
