<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Office leases (docs/superpowers/specs/2026-10-10-office-leases-design.md):
     * a company holds one private office for whole months at a fixed money
     * price. Every billing period is created up front; an invoice points at
     * the period it bills (the "one FK column per source" rule of Phase 2),
     * and the lock grant points at its lease.
     */
    public function up(): void
    {
        Schema::create('office_leases', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('space_id')->constrained()->restrictOnDelete();
            $table->foreignId('private_office_request_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('billed_to', 20);
            $table->string('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies');
            $table->decimal('monthly_amount', 14, 2);
            $table->date('starts_on');
            $table->unsignedSmallInteger('duration_months');
            $table->unsignedSmallInteger('billing_cycle_months');
            $table->date('ends_on');
            $table->string('status', 20)->default('active');
            $table->date('terminated_on')->nullable();
            $table->foreignId('terminated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('termination_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['space_id', 'status']);
            $table->index(['company_id', 'status']);
            $table->index(['provider_id', 'status']);
            $table->index(['status', 'ends_on']);
        });

        Schema::create('office_lease_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_lease_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('amount', 14, 2);
            $table->string('status', 20)->default('upcoming');
            $table->timestamps();

            $table->unique(['office_lease_id', 'sequence']);
            $table->index(['status', 'starts_on']);
        });

        Schema::table('private_office_requests', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('converted_company_id')
                ->constrained('companies')->nullOnDelete();
            $table->string('payer', 20)->nullable()->after('company_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('office_lease_period_id')->nullable()->unique()->after('provider_request_id')
                ->constrained('office_lease_periods')->restrictOnDelete();
            $table->unsignedBigInteger('points')->nullable()->change();
        });

        Schema::table('access_grants', function (Blueprint $table) {
            $table->foreignId('office_lease_id')->nullable()->after('source_id')
                ->constrained('office_leases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('access_grants', fn (Blueprint $table) => $table->dropConstrainedForeignId('office_lease_id'));
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('office_lease_period_id');
            $table->unsignedBigInteger('points')->nullable(false)->change();
        });
        Schema::table('private_office_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('payer');
        });
        Schema::dropIfExists('office_lease_periods');
        Schema::dropIfExists('office_leases');
    }
};
