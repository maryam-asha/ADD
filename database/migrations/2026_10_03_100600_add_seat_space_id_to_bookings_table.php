<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a co-working booking sits: null for the co_space it was booked
     * on, or the room lent to co-working it was placed in once the base
     * seats ran out (docs/decisions/coworking-seat-pool.md, "Seating
     * placement"). Never a desk — only the space, so the member knows which
     * floor and room to head to.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('seat_space_id')->nullable()->after('space_id')->constrained('spaces')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seat_space_id');
        });
    }
};
