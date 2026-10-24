<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The ledger: every change to a stock quantity, append-only. It is
        // the record; inv_stock_balances only keeps its running total.
        Schema::create('inv_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inv_items')->restrictOnDelete();
            $table->string('location_type', 40);
            $table->unsignedBigInteger('location_id');
            $table->string('type', 20);
            // Signed: receipts and transfers in add, issues and transfers
            // out take away; an adjustment is either.
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('quantity_after', 14, 3);
            $table->decimal('average_cost_after', 14, 4);
            // Ties a transfer's two halves together.
            $table->uuid('transfer_id')->nullable()->index();
            $table->string('reason', 20)->nullable();
            // What in the host caused it (a sale, a recorded consumption).
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['item_id', 'location_type', 'location_id']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('inv_stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inv_items')->restrictOnDelete();
            $table->string('location_type', 40);
            $table->unsignedBigInteger('location_id');
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('average_cost', 14, 4)->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['item_id', 'location_type', 'location_id']);
            $table->index(['location_type', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_stock_balances');
        Schema::dropIfExists('inv_stock_movements');
    }
};
