<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->json('name');
            // day: amounts in working days (halves allowed per type);
            // hour: a few hours on one working day.
            $table->string('unit', 8)->default('day');
            $table->boolean('is_paid')->default(true);
            // Unpaid or unlimited leave is still requested and approved,
            // but draws on no balance.
            $table->boolean('tracks_balance')->default(true);
            $table->boolean('allows_half_day')->default(false);
            // Statutory leaves (maternity, ...) run on calendar days.
            $table->boolean('counts_calendar_days')->default(false);
            // Shown as plain "leave" in team calendars (sick leave, ...).
            $table->boolean('hide_reason')->default(false);
            // When set, a request needs an attachment, stored as an encrypted
            // employee document of this type.
            $table->foreignId('attachment_document_type_id')->nullable()->constrained('hr_document_types')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_leave_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
            $table->json('name')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('accrual_method', 16);
            $table->decimal('annual_entitlement', 8, 2)->default(0);
            // [{min_years, entitlement}, ...]: more days with seniority.
            $table->json('service_tiers')->nullable();
            // Null: everything carries over. 0: nothing does.
            $table->decimal('carry_over_max', 8, 2)->nullable();
            // Carried days left unused this many months into the new year expire.
            $table->unsignedSmallInteger('carry_over_expiry_months')->nullable();
            // How far below zero a request may take the balance.
            $table->decimal('max_negative', 8, 2)->default(0);
            // Who it applies to; null means everyone on that axis.
            $table->json('contract_types')->nullable();
            $table->json('job_grade_ids')->nullable();
            $table->json('org_unit_ids')->nullable();
            $table->timestamps();

            $table->index(['leave_type_id', 'effective_from']);
        });

        Schema::create('hr_holidays', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->date('starts_on');
            $table->date('ends_on');
            // Fixed-date holidays (same day every year); lunar ones are
            // entered per year.
            $table->boolean('recurs_annually')->default(false);
            $table->timestamps();

            $table->index(['starts_on', 'ends_on']);
        });

        Schema::create('hr_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('half_day', 16)->nullable();
            $table->decimal('hours', 5, 2)->nullable();
            // In the type's unit, computed at submission.
            $table->decimal('amount', 8, 2);
            $table->string('reason', 1000)->nullable();
            $table->foreignId('document_id')->nullable()->constrained('hr_employee_documents')->nullOnDelete();
            $table->string('status', 16);
            $table->foreignId('approval_request_id')->nullable()->constrained('hr_approval_requests')->nullOnDelete();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['starts_on', 'ends_on']);
        });

        Schema::create('hr_leave_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
            $table->string('kind', 16);
            // Signed: credits positive, usage and expiry negative.
            $table->decimal('amount', 8, 2);
            // When the movement happened; a balance on a day sums up to it.
            $table->date('effective_on');
            // The entitlement year the movement belongs to. Usage draws on
            // the oldest year first; carried years expire on their own.
            $table->unsignedSmallInteger('period_year');
            $table->foreignId('leave_request_id')->nullable()->constrained('hr_leave_requests')->nullOnDelete();
            // Set on scheduled movements (accrual:2026-09, grant:2026, ...)
            // so a rerun never books them twice.
            $table->string('reference', 32)->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['employee_id', 'leave_type_id', 'effective_on']);
            $table->unique(['employee_id', 'leave_type_id', 'reference']);
        });

        Schema::create('hr_coverage_rules', function (Blueprint $table) {
            $table->id();
            // One of the two: everyone in a unit, or everyone in a position.
            $table->foreignId('org_unit_id')->nullable()->constrained('hr_org_units')->cascadeOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('hr_positions')->cascadeOnDelete();
            // At least this many must be at work on every working day.
            $table->unsignedSmallInteger('min_present');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_coverage_rules');
        Schema::dropIfExists('hr_leave_ledger');
        Schema::dropIfExists('hr_leave_requests');
        Schema::dropIfExists('hr_holidays');
        Schema::dropIfExists('hr_leave_policies');
        Schema::dropIfExists('hr_leave_types');
    }
};
