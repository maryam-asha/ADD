<?php

use Add\Hr\Hr;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employees', function (Blueprint $table) {
            $table->id();
            // The host account (config hr.user_model), when linked.
            $table->foreignId('user_id')->nullable()->unique()->constrained(Hr::usersTable())->nullOnDelete();
            $table->string('employee_number', 32)->nullable()->unique();
            $table->json('name');
            $table->string('gender', 16)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->char('nationality', 2)->nullable();
            $table->string('marital_status', 16)->nullable();
            $table->string('phone', 32)->nullable()->index();
            $table->string('personal_email')->nullable();
            $table->string('work_email')->nullable()->unique();
            $table->string('address', 500)->nullable();
            // Encrypted casts: ciphertext is far longer than the value.
            $table->text('national_id')->nullable();
            $table->text('bank_account')->nullable();
            $table->date('hired_on');
            $table->date('probation_ends_on')->nullable();
            $table->date('confirmed_on')->nullable();
            // The last working day.
            $table->date('terminated_on')->nullable();
            $table->string('termination_reason', 32)->nullable();
            // A cache of EmployeeStatusResolver for listing filters.
            $table->string('status', 16)->index();
            // When the host was told the employment ended (deprovision).
            $table->timestamp('access_revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_employee_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('name');
            $table->string('relation', 50);
            $table->string('phone', 32);
            $table->timestamps();
        });

        Schema::create('hr_employee_dependents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('name');
            $table->string('relation', 50);
            $table->date('date_of_birth')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_employment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->string('type', 32);
            $table->date('effective_on');
            // Only suspensions span a range.
            $table->date('effective_to')->nullable();
            $table->string('reason', 32)->nullable();
            $table->text('notes')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_on']);
        });

        Schema::create('hr_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->string('type', 32);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('notice_period_days')->nullable();
            $table->string('reference', 100)->nullable();
            $table->foreignId('event_id')->nullable()->constrained('hr_employment_events')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'starts_on']);
        });

        Schema::create('hr_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('org_unit_id')->constrained('hr_org_units')->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('hr_positions')->restrictOnDelete();
            $table->foreignId('job_grade_id')->nullable()->constrained('hr_job_grades')->restrictOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->foreignId('work_location_id')->nullable()->constrained('hr_work_locations')->nullOnDelete();
            $table->json('job_title')->nullable();
            $table->string('employment_type', 16);
            $table->string('work_mode', 16);
            $table->foreignId('event_id')->nullable()->constrained('hr_employment_events')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
            $table->index(['position_id', 'effective_to']);
            $table->index(['manager_id', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_assignments');
        Schema::dropIfExists('hr_contracts');
        Schema::dropIfExists('hr_employment_events');
        Schema::dropIfExists('hr_employee_dependents');
        Schema::dropIfExists('hr_employee_contacts');
        Schema::dropIfExists('hr_employees');
    }
};
