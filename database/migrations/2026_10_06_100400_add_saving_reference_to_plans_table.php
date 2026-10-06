<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a plan's built-in saving is measured against: another plan, or a
 * space's hourly rate, times `reference_units` — the 12-month plan against
 * twelve monthly ones, the 25-hour bundle against 25 hours
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
 * Exactly one reference or neither; the form request enforces it. Deleting
 * the reference only drops the comparison.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->foreignId('reference_plan_id')->nullable()->after('price_points')->constrained('plans')->nullOnDelete();
            $table->foreignId('reference_space_id')->nullable()->after('reference_plan_id')->constrained('spaces')->nullOnDelete();
            $table->unsignedInteger('reference_units')->nullable()->after('reference_space_id');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reference_plan_id');
            $table->dropConstrainedForeignId('reference_space_id');
            $table->dropColumn('reference_units');
        });
    }
};
