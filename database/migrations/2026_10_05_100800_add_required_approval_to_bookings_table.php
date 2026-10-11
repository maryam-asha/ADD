<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the booking went through review — its space's `requires_approval`
 * as it stood when the booking was made, so the stepper never shows a review
 * that never happened, even once the space's setting has changed.
 *
 * Backfilled from what each row already proves: a decision was recorded
 * (`approved_at`), or it is or ended pending/rejected. A cancelled row with
 * no decision proves nothing either way, so it reads its space's current
 * setting — the only best guess available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('required_approval')->default(false)->after('status');
        });

        DB::table('bookings')
            ->where(fn ($query) => $query->whereNotNull('approved_at')->orWhereIn('status', ['pending', 'rejected']))
            ->update(['required_approval' => true]);

        DB::table('bookings')
            ->where('status', 'cancelled')
            ->whereNull('approved_at')
            ->whereIn('space_id', DB::table('spaces')->where('requires_approval', true)->select('id'))
            ->update(['required_approval' => true]);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('required_approval');
        });
    }
};
