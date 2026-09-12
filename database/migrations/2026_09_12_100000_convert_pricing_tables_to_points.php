<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADD Point becomes the unit of account
 * (docs/superpowers/specs/2026-09-12-add-point-b-pricing-design.md).
 * Prices are whole point counts; currency is derived at read time, so
 * `pricing_currency` stops being a stored concept here.
 *
 * No backfill: every row in these tables is seeded test data as of
 * 2026-09-12, re-created by PlanSeeder/SpaceSeeder with point values.
 *
 * unsignedInteger, not decimal — a point is a cardinal quantity, and
 * PRD decision #15's guard (MoneyIsDecimalOnlyTest) forbids only
 * float/double, which this is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // The FK must go before the column it constrains, or MySQL
            // refuses the drop. SQLite (the test connection) tolerates
            // either order, so getting this wrong passes tests and fails
            // on deploy.
            $table->dropForeign(['pricing_currency']);
            $table->dropColumn(['price', 'pricing_currency', 'overage_rate']);
            $table->unsignedInteger('price_points')->default(0)->after('subtitle');
            $table->unsignedInteger('overage_rate_points')->nullable()->after('included_hours');
        });

        Schema::table('spaces', function (Blueprint $table) {
            $table->dropForeign(['pricing_currency']);
            $table->dropColumn(['hourly_rate', 'pricing_currency']);
            $table->unsignedInteger('hourly_rate_points')->nullable()->after('capacity');
        });

        Schema::table('plan_balances', function (Blueprint $table) {
            $table->dropColumn('amount');
            $table->unsignedInteger('amount_points')->default(0)->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['price_points', 'overage_rate_points']);
            $table->decimal('price', 10, 2)->default(0);
            $table->string('pricing_currency', 3)->default('USD');
            $table->decimal('overage_rate', 10, 2)->nullable();
            $table->foreign('pricing_currency')->references('code')->on('currencies')->restrictOnDelete();
        });

        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn('hourly_rate_points');
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->string('pricing_currency', 3)->nullable();
            $table->foreign('pricing_currency')->references('code')->on('currencies')->restrictOnDelete();
        });

        Schema::table('plan_balances', function (Blueprint $table) {
            $table->dropColumn('amount_points');
            $table->decimal('amount', 10, 2)->default(0);
        });
    }
};
