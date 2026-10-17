<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_cost_centers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->json('name');
            // Optionally a host record the cost belongs to (ADD: a Lab
            // project, a grant).
            $table->nullableMorphs('costable');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('cost_center_id')->constrained('hr_cost_centers')->restrictOnDelete();
            // One set per period sums to 100.
            $table->decimal('percent', 5, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });

        Schema::create('hr_timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('week_start');
            $table->string('status', 16);
            $table->decimal('total_hours', 6, 2)->default(0);
            $table->foreignId('approval_request_id')->nullable()->constrained('hr_approval_requests')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'week_start']);
            $table->index(['week_start', 'status']);
        });

        Schema::create('hr_timesheet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timesheet_id')->constrained('hr_timesheets')->cascadeOnDelete();
            $table->foreignId('cost_center_id')->constrained('hr_cost_centers')->restrictOnDelete();
            $table->date('work_date');
            $table->decimal('hours', 5, 2);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['cost_center_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_timesheet_entries');
        Schema::dropIfExists('hr_timesheets');
        Schema::dropIfExists('hr_cost_allocations');
        Schema::dropIfExists('hr_cost_centers');
    }
};
