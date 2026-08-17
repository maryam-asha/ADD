<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-space override of the global booking.cancellation_window_minutes
     * setting (decision #4) — a plain column, not a scoped Setting row,
     * per App\Domain\Settings\Enums\SettingScope's own docblock: a
     * per-space override belongs on that domain's own model, not a new
     * SettingScope case.
     */
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->unsignedInteger('cancellation_window_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn('cancellation_window_minutes');
        });
    }
};
