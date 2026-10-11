<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider invoicing (docs/superpowers/specs/2026-10-10-provider-invoicing-design.md):
 * a debt in money, outside the points ledger and the cash drawer. Each
 * source of an invoice is its own nullable FK — today only
 * provider_request_id — so a later source (office leases) adds a column
 * rather than a morph. `paid_amount` is the sum of active payments, kept
 * under the invoice's row lock; "overdue" is derived, never stored.
 * A provider or provider request that has an invoice cannot be deleted
 * (restrict): intended for financial records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('contract_ref');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('billed_to', 20);
            $table->foreignId('provider_request_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->unsignedBigInteger('points');
            $table->string('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies');
            $table->decimal('computed_amount', 14, 2);
            $table->decimal('amount', 14, 2);
            $table->decimal('exchange_rate_to_base', 20, 10);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->string('status', 20)->default('unpaid');
            $table->date('issued_at');
            $table->date('due_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('received_amount', 14, 2);
            $table->string('received_currency', 3);
            $table->foreign('received_currency')->references('code')->on('currencies');
            $table->decimal('exchange_rate', 20, 10);
            $table->decimal('amount', 14, 2);
            $table->string('method', 20);
            $table->string('receipt_number')->nullable();
            $table->date('paid_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoices');
        Schema::table('providers', fn (Blueprint $table) => $table->dropColumn('payment_terms_days'));
    }
};
