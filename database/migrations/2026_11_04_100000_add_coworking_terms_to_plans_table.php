<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a plan includes coworking and how (CoworkingSeat), its
     * coworking hours (null = unlimited), and the cycle its hours and
     * discount use-limits run on (AllowanceCycle). The existing
     * access_window_* columns stay: the daily coworking window, none = all day.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('coworking_seat', 20)->default('none')->after('duration_days');
            $table->decimal('coworking_hours', 8, 2)->nullable()->after('coworking_seat');
            $table->string('allowance_cycle', 20)->default('period')->after('coworking_hours');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['coworking_seat', 'coworking_hours', 'allowance_cycle']);
        });
    }
};
