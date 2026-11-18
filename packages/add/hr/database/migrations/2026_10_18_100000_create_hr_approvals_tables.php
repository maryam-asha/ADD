<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_approval_flows', function (Blueprint $table) {
            $table->id();
            // One flow per request type (leave, overtime, ...). A type with
            // no active flow goes to the employee's direct manager.
            $table->string('request_type', 32)->unique();
            $table->json('name')->nullable();
            // [{approver, ability?, employee_id?, timeout_hours?, on_timeout?}, ...]
            $table->json('steps');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_type', 32);
            $table->morphs('subject');
            // Whose request it is, and which account filed it (the employee
            // themselves, or HR on their behalf).
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->string('status', 16)->index();
            $table->unsignedSmallInteger('current_step')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['request_type', 'status']);
        });

        Schema::create('hr_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained('hr_approval_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort');
            $table->string('approver_type', 32);
            // Resolved when the step becomes current, not at submission, so a
            // manager change mid-flow reaches the right person. Exactly one
            // of the two is set once resolved.
            $table->foreignId('approver_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->string('approver_ability', 64)->nullable();
            $table->string('status', 16);
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->string('decided_via', 16)->nullable();
            $table->string('comment', 1000)->nullable();
            $table->unsignedSmallInteger('timeout_hours')->nullable();
            $table->string('on_timeout', 16)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['approval_request_id', 'sort']);
            $table->index(['status', 'approver_employee_id']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('hr_approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegator_employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('delegate_employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            // Null: every request type.
            $table->string('request_type', 32)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['delegate_employee_id', 'starts_on', 'ends_on'], 'hr_approval_delegations_delegate_dates_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_approval_delegations');
        Schema::dropIfExists('hr_approval_steps');
        Schema::dropIfExists('hr_approval_requests');
        Schema::dropIfExists('hr_approval_flows');
    }
};
