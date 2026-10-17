<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_courses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->json('title');
            $table->json('description')->nullable();
            // The outside provider, if any.
            $table->string('provider')->nullable();
            // in_person | online | blended
            $table->string('delivery', 16);
            $table->decimal('duration_hours', 6, 2)->nullable();
            // What one seat costs the organisation.
            $table->decimal('cost', 14, 4)->nullable();
            $table->string('currency', 3)->nullable();
            // Completing the course grants this certification, valid this long
            // (null: never expires).
            $table->json('certification_name')->nullable();
            $table->unsignedSmallInteger('certification_valid_months')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_training_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('hr_courses')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('location')->nullable();
            $table->string('trainer')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            // Employees may enroll themselves from self-service.
            $table->boolean('self_enrollment')->default(false);
            // scheduled | completed | cancelled
            $table->string('status', 16);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_on']);
        });

        Schema::create('hr_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_session_id')->constrained('hr_training_sessions')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            // enrolled | completed | no_show | cancelled
            $table->string('status', 16);
            $table->date('completed_on')->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->unsignedBigInteger('enrolled_by')->nullable();
            $table->timestamps();

            $table->unique(['training_session_id', 'employee_id']);
            $table->index('employee_id');
        });

        Schema::create('hr_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            // Set when granted by completing a course.
            $table->foreignId('course_id')->nullable()->constrained('hr_courses')->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('hr_enrollments')->nullOnDelete();
            $table->json('name');
            $table->string('issuer')->nullable();
            $table->date('issued_on');
            $table->date('expires_on')->nullable();
            $table->string('reference', 100)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'expires_on']);
        });

        Schema::create('hr_learning_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            // A catalogue course, or an outside one described by title/provider.
            $table->foreignId('course_id')->nullable()->constrained('hr_courses')->nullOnDelete();
            $table->string('title');
            $table->string('provider')->nullable();
            $table->date('starts_on')->nullable();
            // Charged to the employee's learning budget of `year` once approved.
            $table->unsignedSmallInteger('year');
            $table->decimal('amount', 14, 4);
            $table->string('currency', 3);
            $table->text('justification')->nullable();
            // pending | approved | rejected | cancelled
            $table->string('status', 16);
            $table->foreignId('approval_request_id')->nullable()->constrained('hr_approval_requests')->nullOnDelete();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });

        Schema::create('hr_learning_budget_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            // allocation | spend | adjustment; the balance is their sum.
            $table->string('kind', 16);
            // Signed: allocations add, spending subtracts.
            $table->decimal('amount', 14, 4);
            $table->string('currency', 3);
            $table->foreignId('learning_request_id')->nullable()->constrained('hr_learning_requests')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_learning_budget_entries');
        Schema::dropIfExists('hr_learning_requests');
        Schema::dropIfExists('hr_certifications');
        Schema::dropIfExists('hr_enrollments');
        Schema::dropIfExists('hr_training_sessions');
        Schema::dropIfExists('hr_courses');
    }
};
