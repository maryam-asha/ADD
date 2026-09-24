<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `overage_rate_points` never priced anything.
 *
 * It was introduced as a catalog attribute only — the original plans
 * migration said so in as many words ("not applied by any logic yet") — and
 * nothing ever came to apply it: every charge in the app is computed by
 * AmountCalculator from `spaces.hourly_rate_points`, never from this column.
 * `docs/superpowers/specs/2026-09-12-add-point-c-wallet-design.md` left the
 * choice open as "convert it if it is to be charged, or drop the column";
 * this is that decision, resolved as drop.
 *
 * Plan-hour overrun is already answered without it: when a member's granted
 * hours run out, the remaining time is billed at the space's ordinary rate,
 * exactly as it is for a visitor with no plan at all
 * (docs/decisions/plan-access-windows.md). A second, unused rate on the plan
 * only invited the two to drift apart.
 *
 * `down()` restores the column, not its values — nothing read them, so there
 * is nothing to restore them for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('overage_rate_points');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('overage_rate_points')->nullable()->after('included_hours');
        });
    }
};
