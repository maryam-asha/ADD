<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `included_hours` counted hours but was spent as points.
 *
 * It was the original, simpler way to grant a subscriber something on
 * purchase, and it only ever fired as a FALLBACK: `grantPlanBalances()` used
 * it when — and only when — a plan carried no `plan_balances` rows at all.
 * Every seeded plan has balances, so in practice the branch was already dead.
 *
 * It was also wrong where it did fire. Its own `TODO(spec-C)` said so: the
 * column holds an hours figure, the ledger holds points, and the value was
 * written through 1:1 because that was the pre-points behaviour — so
 * `included_hours = 20` granted 20 POINTS, not 20 hours' worth of them. The
 * real hours->points rule was never decided.
 *
 * Nothing replaced it because two things already cover the ground properly:
 * `plan_balances` for point-denominated grants, and `plan_resource_grants`
 * (consumed through ResourceQuotaService) for natural-unit quotas like the
 * access-window hours in docs/decisions/plan-access-windows.md. Both are
 * explicit about their unit; this column never was.
 *
 * `down()` restores the column and its default, not its values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('included_hours');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('included_hours', 8, 2)->default(0)->after('duration_days');
        });
    }
};
