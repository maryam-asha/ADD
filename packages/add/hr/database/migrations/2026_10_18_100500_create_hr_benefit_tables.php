<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_benefits', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->json('name');
            $table->json('description')->nullable();
            // cash_allowance | in_kind_credit | entitlement
            $table->string('kind', 24);
            // monthly | quarterly | yearly | one_time
            $table->string('frequency', 16);
            // The month a yearly benefit is granted in.
            $table->unsignedTinyInteger('grant_month')->nullable();
            // Per grant: money (cash, entitlement) or units of `unit` (in kind).
            $table->decimal('amount', 14, 4);
            $table->string('currency', 3)->nullable();
            $table->string('unit', 16)->nullable();
            // What one grant costs the employer; a cash allowance's cost is
            // its payslip line.
            $table->decimal('employer_cost', 14, 4)->nullable();
            $table->string('cost_currency', 3)->nullable();
            // The earning a cash allowance is paid through.
            $table->foreignId('pay_component_id')->nullable()->constrained('hr_pay_components')->restrictOnDelete();
            // Options for the host's BenefitFulfiller (in kind only).
            $table->json('fulfillment')->nullable();
            $table->unsignedSmallInteger('eligible_after_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_benefit_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('benefit_id')->constrained('hr_benefits')->cascadeOnDelete();
            // Exactly one target: an employee, a grade, or a unit with its
            // sub-units.
            $table->foreignId('employee_id')->nullable()->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('job_grade_id')->nullable()->constrained('hr_job_grades')->cascadeOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('hr_org_units')->cascadeOnDelete();
            // Overrides of the benefit's amount and cost.
            $table->decimal('amount', 14, 4)->nullable();
            $table->decimal('employer_cost', 14, 4)->nullable();
            // An employee kept out of what their grade or unit receives.
            $table->boolean('excluded')->default(false);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['benefit_id', 'effective_from']);
        });

        Schema::create('hr_benefit_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('benefit_id')->constrained('hr_benefits')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('benefit_assignment_id')->nullable()->constrained('hr_benefit_assignments')->nullOnDelete();
            // YYYY-MM | YYYY-Qn | YYYY | once
            $table->string('period', 16);
            $table->date('granted_on');
            $table->decimal('amount', 14, 4);
            $table->string('currency', 3)->nullable();
            $table->string('unit', 16)->nullable();
            $table->decimal('employer_cost', 14, 4)->nullable();
            $table->string('cost_currency', 3)->nullable();
            // pending | fulfilled | failed | cancelled
            $table->string('status', 16);
            // The host's record of the delivery (a wallet transaction id).
            $table->string('reference', 64)->nullable();
            $table->string('failure', 255)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('fulfilled_at')->nullable();
            $table->foreignId('payslip_id')->nullable()->constrained('hr_payslips')->nullOnDelete();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamps();

            $table->unique(['benefit_id', 'employee_id', 'period']);
            $table->index(['employee_id', 'granted_on']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_benefit_grants');
        Schema::dropIfExists('hr_benefit_assignments');
        Schema::dropIfExists('hr_benefits');
    }
};
