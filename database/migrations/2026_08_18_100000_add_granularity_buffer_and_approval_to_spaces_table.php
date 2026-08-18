<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-space overrides, same pattern as the existing
     * cancellation_window_minutes column — null-coalesced at the consuming
     * call site against the already-seeded booking.slot_granularity_minutes
     * (30) / booking.buffer_minutes (0) settings. requires_approval has no
     * global fallback: a per-space toggle, not a value worth centralizing.
     */
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->unsignedInteger('slot_granularity_minutes')->nullable();
            $table->unsignedInteger('buffer_minutes')->nullable();
            $table->boolean('requires_approval')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn(['slot_granularity_minutes', 'buffer_minutes', 'requires_approval']);
        });
    }
};
