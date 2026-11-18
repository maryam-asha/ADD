<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A subscription holds its seats in one branch's co-working pool
     * (docs/decisions/coworking-seat-pool.md), so it needs to know which
     * branch, and how many seats: one for a member, as many as were sold for
     * a company.
     *
     * `branch_id` is nullable only so existing rows can be filled in by the
     * next migration and so a branch-less install still works; the model
     * fills it on every save through MembershipBranchResolver.
     */
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('owner_id')->constrained('branches')->restrictOnDelete();
            $table->unsignedSmallInteger('seats')->default(1)->after('branch_id');
            $table->index(['branch_id', 'status', 'current_period_end']);
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'status', 'current_period_end']);
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn('seats');
        });
    }
};
