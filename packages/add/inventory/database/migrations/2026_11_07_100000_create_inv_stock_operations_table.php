<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One stock operation as staff recorded it: what applies to all of
        // its lines once. Its ledger rows carry its id as operation_id.
        // Written once, never changed (like the ledger).
        Schema::create('inv_stock_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // receive | issue | write_off | transfer | adjust
            $table->string('kind', 12);
            $table->foreignId('warehouse_id')->constrained('inv_warehouses')->restrictOnDelete();
            // The warehouse a transfer arrives in.
            $table->foreignId('to_warehouse_id')->nullable()->constrained('inv_warehouses')->restrictOnDelete();
            // The day it happened, for the lists and their filters. Balances
            // and average cost still follow the order things were recorded.
            $table->date('operation_date');
            $table->string('reference', 60)->nullable();
            // Why stock left by hand (an IssueReason), for issues and write-offs.
            $table->string('reason', 20)->nullable();
            // Why, in the person's words.
            $table->text('note')->nullable();
            // Receipts: average (the warehouse's current average) or entered.
            $table->string('cost_method', 10)->nullable();
            $table->string('attachment_url', 2048)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->timestamp('created_at')->nullable();

            $table->index(['operation_date', 'created_at']);
            $table->index('kind');
        });

        Schema::table('inv_stock_movements', function (Blueprint $table) {
            // A note on one line of an operation ("two cartons dented").
            $table->text('line_note')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('inv_stock_movements', function (Blueprint $table) {
            $table->dropColumn('line_note');
        });

        Schema::dropIfExists('inv_stock_operations');
    }
};
