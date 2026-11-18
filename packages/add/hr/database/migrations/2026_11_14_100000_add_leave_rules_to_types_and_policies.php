<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The leave rules a company sets for itself instead of the package fixing
 * them: per-request limits on a type, and on a policy when the yearly grant
 * renews, when a higher seniority tier starts, what a new hire gets, and
 * whether unused days carry over at all.
 *
 * Every default is what the package did before, so existing policies keep
 * their behaviour. carry_over_mode is written for every existing policy from
 * carry_over_max, which until now carried the meaning on its own (empty:
 * no limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table) {
            // In the type's unit; null: no limit.
            $table->decimal('min_request', 8, 2)->nullable()->after('allows_half_day');
            $table->decimal('max_request', 8, 2)->nullable()->after('min_request');
        });

        Schema::table('hr_leave_policies', function (Blueprint $table) {
            $table->string('grant_cycle', 16)->default('calendar_year')->after('accrual_method');
            $table->string('tier_timing', 16)->default('next_cycle')->after('service_tiers');
            $table->string('hire_grant', 16)->default('prorated')->after('tier_timing');
            $table->string('carry_over_mode', 16)->nullable()->after('hire_grant');
        });

        DB::table('hr_leave_policies')->whereNull('carry_over_max')->update(['carry_over_mode' => 'unlimited']);
        DB::table('hr_leave_policies')->where('carry_over_max', 0)->update(['carry_over_mode' => 'none']);
        DB::table('hr_leave_policies')->where('carry_over_max', '>', 0)->update(['carry_over_mode' => 'capped']);
    }

    public function down(): void
    {
        Schema::table('hr_leave_policies', function (Blueprint $table) {
            $table->dropColumn(['grant_cycle', 'tier_timing', 'hire_grant', 'carry_over_mode']);
        });

        Schema::table('hr_leave_types', function (Blueprint $table) {
            $table->dropColumn(['min_request', 'max_request']);
        });
    }
};
