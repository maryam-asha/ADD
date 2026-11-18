<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_pay_components', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->json('name');
            // earning | deduction | employer_contribution | in_kind
            $table->string('kind', 24);
            // A registered calculator key (fixed, percent_of, rate_table, ...).
            $table->string('calculator', 32);
            $table->json('config')->nullable();
            // Every employee (insurance, tax, overtime) or only those with a
            // compensation line for it (basic salary, allowances).
            $table->boolean('applies_to_all')->default(false);
            $table->boolean('taxable')->default(false);
            $table->boolean('insurable')->default(false);
            // Calculation order: later components may build on earlier lines.
            $table->unsignedSmallInteger('sort')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_compensations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('pay_component_id')->constrained('hr_pay_components')->restrictOnDelete();
            // Monthly amount for fixed components, a rate for per-day ones,
            // an override for percentage ones.
            $table->decimal('amount', 14, 4);
            $table->string('currency', 3);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('event_id')->nullable()->constrained('hr_employment_events')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });

        Schema::create('hr_rate_tables', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->json('name');
            // percent: one rate; brackets: progressive bands.
            $table->string('kind', 16);
            $table->timestamps();
        });

        Schema::create('hr_rate_table_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_table_id')->constrained('hr_rate_tables')->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('rate', 9, 4)->nullable();
            // Cap on the base a percent applies to (insurance ceilings).
            $table->decimal('max_base', 14, 2)->nullable();
            // [{up_to: number|null, rate: percent}, ...] ascending.
            $table->json('brackets')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['rate_table_id', 'effective_from']);
        });

        Schema::create('hr_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('kind', 16);
            $table->string('currency', 3);
            $table->string('status', 16);
            $table->json('employee_ids')->nullable();
            $table->json('exchange_rates')->nullable();
            $table->boolean('parallel_run')->default(false);
            $table->timestamp('reconciled_at')->nullable();
            $table->unsignedBigInteger('reconciled_by')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->unsignedBigInteger('opened_by')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->unsignedBigInteger('calculated_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['year', 'month', 'kind']);
        });

        Schema::create('hr_payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('pay_component_id')->constrained('hr_pay_components')->restrictOnDelete();
            $table->decimal('amount', 14, 4);
            $table->string('currency', 3);
            $table->string('note', 500);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->string('currency', 3);
            $table->decimal('gross', 14, 2);
            $table->decimal('deductions', 14, 2);
            $table->decimal('net', 14, 2);
            $table->decimal('employer_cost', 14, 2);
            $table->unsignedTinyInteger('employed_days');
            $table->unsignedTinyInteger('days_in_month');
            // A snapshot of who the employee was this month (name, number,
            // title, unit), so the slip never changes after the fact.
            $table->json('snapshot');
            $table->json('warnings')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('hr_payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained('hr_payslips')->cascadeOnDelete();
            $table->foreignId('pay_component_id')->nullable()->constrained('hr_pay_components')->nullOnDelete();
            $table->string('code', 32);
            $table->json('name');
            $table->string('kind', 24);
            $table->string('basis', 255)->nullable();
            $table->decimal('quantity', 12, 4)->nullable();
            $table->decimal('rate', 14, 4)->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('original_amount', 14, 4)->nullable();
            $table->string('original_currency', 3)->nullable();
            $table->boolean('taxable')->default(false);
            $table->boolean('insurable')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('hr_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->unsignedSmallInteger('installments');
            $table->decimal('installment_amount', 14, 2);
            $table->unsignedSmallInteger('first_year');
            $table->unsignedTinyInteger('first_month');
            $table->string('reason', 1000)->nullable();
            $table->string('status', 16);
            $table->foreignId('approval_request_id')->nullable()->constrained('hr_approval_requests')->nullOnDelete();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });

        Schema::create('hr_loan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('hr_loans')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('amount', 14, 2);
            // due | deducted | settled
            $table->string('status', 16);
            $table->foreignId('payslip_id')->nullable()->constrained('hr_payslips')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['year', 'month', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_loan_installments');
        Schema::dropIfExists('hr_loans');
        Schema::dropIfExists('hr_payslip_lines');
        Schema::dropIfExists('hr_payslips');
        Schema::dropIfExists('hr_payroll_adjustments');
        Schema::dropIfExists('hr_payroll_runs');
        Schema::dropIfExists('hr_rate_table_versions');
        Schema::dropIfExists('hr_rate_tables');
        Schema::dropIfExists('hr_compensations');
        Schema::dropIfExists('hr_pay_components');
    }
};
