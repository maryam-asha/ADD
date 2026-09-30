<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the walk-in sat in a seat its subscription already held, or
     * took a free seat from the branch's co-working pool — recorded at
     * check-in, so reception can see which it was
     * (docs/decisions/coworking-seat-pool.md).
     */
    public function up(): void
    {
        Schema::table('walkin_sessions', function (Blueprint $table) {
            $table->boolean('seat_covered')->default(false)->after('membership_id');
        });
    }

    public function down(): void
    {
        Schema::table('walkin_sessions', function (Blueprint $table) {
            $table->dropColumn('seat_covered');
        });
    }
};
