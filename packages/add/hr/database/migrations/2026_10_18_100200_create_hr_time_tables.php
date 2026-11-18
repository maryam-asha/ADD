<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_shift_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->json('name');
            // Local wall-clock `H:i`. An end at or before the start runs past
            // midnight into the next day.
            $table->string('starts_at', 5);
            $table->string('ends_at', 5);
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_work_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->json('name');
            // fixed: the same shift each weekday; roster: from roster
            // entries; flexible: weekly hours, no fixed window.
            $table->string('kind', 16);
            // {"1": shift_template_id|null, ..., "7": ...} (ISO weekdays).
            $table->json('weekly')->nullable();
            $table->decimal('weekly_hours', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('hr_assignments', function (Blueprint $table) {
            $table->foreignId('work_pattern_id')->nullable()->after('work_mode')->constrained('hr_work_patterns')->nullOnDelete();
        });

        Schema::create('hr_roster_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('work_date');
            // Null: a rest day.
            $table->foreignId('shift_template_id')->nullable()->constrained('hr_shift_templates')->restrictOnDelete();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index('work_date');
        });

        Schema::create('hr_attendance_policies', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // Minutes late (or early out) before it counts.
            $table->unsignedSmallInteger('grace_minutes')->default(10);
            $table->unsignedSmallInteger('early_leave_grace_minutes')->default(10);
            // Shorter stays past the shift are not overtime.
            $table->unsignedSmallInteger('min_overtime_minutes')->default(30);
            $table->boolean('overtime_requires_pre_approval')->default(false);
            // How far an employee's own time may be from a card scan and
            // still be confirmed by it.
            $table->unsignedSmallInteger('entry_tolerance_minutes')->default(15);
            $table->timestamps();
        });

        Schema::create('hr_attendance_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            // SHA-256 of the token printed in the card's QR; the token itself
            // is shown once, when the card is issued.
            $table->string('token_hash', 64)->unique();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'revoked_at']);
        });

        Schema::create('hr_attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            // UTC. Punches carry no direction: the first of a day is the
            // arrival and the last the departure, as on a fingerprint clock.
            $table->dateTime('punched_at');
            $table->string('source', 16);
            $table->string('device_type', 32)->nullable();
            $table->string('device_id', 64)->nullable();
            // Set by devices that may resend (an offline queue): a resend is
            // recognised and not booked twice.
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['employee_id', 'punched_at']);
        });

        Schema::create('hr_attendance_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->string('status', 16);
            $table->foreignId('shift_template_id')->nullable()->constrained('hr_shift_templates')->nullOnDelete();
            $table->dateTime('scheduled_start')->nullable();
            $table->dateTime('scheduled_end')->nullable();
            $table->dateTime('first_in')->nullable();
            $table->dateTime('last_out')->nullable();
            $table->unsignedSmallInteger('punch_count')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index(['work_date', 'status']);
        });

        Schema::create('hr_attendance_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_day_id')->constrained('hr_attendance_days')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->string('type', 24);
            $table->string('status', 16);
            $table->string('resolution', 16)->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['attendance_day_id', 'type']);
            $table->index(['status', 'work_date']);
        });

        Schema::create('hr_attendance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('work_date');
            // UTC instants the employee says they arrived / left.
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('status', 16);
            $table->foreignId('approval_request_id')->nullable()->constrained('hr_approval_requests')->nullOnDelete();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'work_date']);
        });

        Schema::create('hr_overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->unsignedSmallInteger('minutes');
            // pre: asked before working it; post: justified afterwards.
            $table->string('kind', 8);
            $table->string('reason', 1000);
            $table->string('status', 16);
            $table->foreignId('approval_request_id')->nullable()->constrained('hr_approval_requests')->nullOnDelete();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'work_date']);
        });

        Schema::create('hr_time_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 16);
            $table->timestamp('locked_at')->nullable();
            $table->unsignedBigInteger('locked_by')->nullable();
            $table->timestamps();

            $table->unique(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_time_periods');
        Schema::dropIfExists('hr_overtime_requests');
        Schema::dropIfExists('hr_attendance_entries');
        Schema::dropIfExists('hr_attendance_exceptions');
        Schema::dropIfExists('hr_attendance_days');
        Schema::dropIfExists('hr_attendance_punches');
        Schema::dropIfExists('hr_attendance_cards');
        Schema::dropIfExists('hr_attendance_policies');
        Schema::dropIfExists('hr_roster_entries');
        Schema::table('hr_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_pattern_id');
        });
        Schema::dropIfExists('hr_work_patterns');
        Schema::dropIfExists('hr_shift_templates');
    }
};
