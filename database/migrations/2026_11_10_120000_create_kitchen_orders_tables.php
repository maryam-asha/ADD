<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A member's order to the kitchen, billed to the open coworking session
     * or checked-in booking it was sent from (`billable`, by morph alias).
     * Its lines keep the price in points at the moment it was sent; stock
     * is drawn only when the kitchen accepts it.
     */
    public function up(): void
    {
        Schema::create('kitchen_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            // The branch's kitchen store when the order was sent.
            $table->foreignId('warehouse_id')->constrained('inv_warehouses')->restrictOnDelete();
            $table->string('billable_type', 40);
            $table->unsignedBigInteger('billable_id');
            $table->string('delivery_type', 20);
            $table->foreignId('seat_desk_id')->nullable()->constrained('seats_desks')->nullOnDelete();
            $table->foreignId('space_id')->nullable()->constrained('spaces')->nullOnDelete();
            $table->string('delivery_note', 200)->nullable();
            $table->string('source', 10);
            $table->string('status', 20)->index();
            $table->unsignedInteger('total_points');
            $table->string('rejection_reason', 300)->nullable();
            $table->string('cancelled_by', 10)->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['billable_type', 'billable_id']);
        });

        Schema::create('kitchen_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kitchen_order_id')->constrained('kitchen_orders')->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained('sale_items')->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inv_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price_points');
            $table->unsignedInteger('line_points');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_order_lines');
        Schema::dropIfExists('kitchen_orders');
    }
};
