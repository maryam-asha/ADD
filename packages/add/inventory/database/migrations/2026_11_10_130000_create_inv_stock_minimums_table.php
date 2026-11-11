<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The least of an item a store should hold. `alerted_at` is set
        // when stock first falls below it, so the warning goes out once;
        // restocking to the minimum clears it and re-arms the warning.
        Schema::create('inv_stock_minimums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('inv_warehouses')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inv_items')->cascadeOnDelete();
            $table->decimal('minimum', 14, 3);
            $table->timestamp('alerted_at')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_stock_minimums');
    }
};
