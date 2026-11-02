<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plan hours spent per person, per allowance, per cycle (PlanHourUsage)
     * — replacing the space-hours resource grant, which counted one pool for
     * a whole subscription.
     */
    public function up(): void
    {
        Schema::create('plan_hour_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained('memberships')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_space_hour_id')->nullable()->constrained('plan_space_hours')->nullOnDelete();
            $table->nullableMorphs('usable'); // null only for hours converted from a consumption no session was linked to
            $table->dateTime('cycle_start');
            $table->unsignedInteger('seconds');
            $table->timestamps();

            $table->index(['membership_id', 'user_id', 'plan_space_hour_id', 'cycle_start'], 'plan_hour_usages_allowance_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_hour_usages');
    }
};
