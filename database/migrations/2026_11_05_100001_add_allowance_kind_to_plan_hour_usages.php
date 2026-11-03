<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which allowance an hour usage spent, said outright: the plan's own
     * coworking hours, or a free-hours benefit. A null plan_space_hour_id
     * alone cannot say it — the benefit's row is nulled when an admin removes
     * it, and its hours must not then be read as coworking hours.
     */
    public function up(): void
    {
        Schema::table('plan_hour_usages', function (Blueprint $table) {
            $table->string('allowance_kind', 20)->default('coworking')->after('plan_space_hour_id');
            $table->index(['membership_id', 'user_id', 'allowance_kind', 'cycle_start'], 'plan_hour_usages_kind_index');
        });

        DB::table('plan_hour_usages')->whereNotNull('plan_space_hour_id')->update(['allowance_kind' => 'space']);
    }

    public function down(): void
    {
        Schema::table('plan_hour_usages', function (Blueprint $table) {
            $table->dropIndex('plan_hour_usages_kind_index');
            $table->dropColumn('allowance_kind');
        });
    }
};
