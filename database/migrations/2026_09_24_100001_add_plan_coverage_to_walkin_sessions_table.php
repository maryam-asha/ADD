<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Splitting a walk-in's bill at its plan's access-window boundary
 * (docs/decisions/plan-access-windows.md).
 *
 * `membership_id` is stamped at check-in rather than resolved again at
 * checkout: it pins WHICH subscription was in force when the session
 * started, so a membership that expires mid-session, or is changed before
 * the member checks out, cannot retroactively rewrite the bill.
 *
 * `plan_covered_seconds` is what the plan actually absorbed, after the
 * remaining hour quota capped it — not merely what fell inside the window.
 * Stored so the settled bill stays explainable after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('walkin_sessions', function (Blueprint $table) {
            $table->foreignId('membership_id')
                ->nullable()
                ->after('user_id')
                ->constrained('memberships')
                ->nullOnDelete();
            $table->unsignedInteger('plan_covered_seconds')->default(0)->after('checked_out_at');
        });
    }

    public function down(): void
    {
        Schema::table('walkin_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('membership_id');
            $table->dropColumn('plan_covered_seconds');
        });
    }
};
