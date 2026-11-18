<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Selling stock at the reception desk (a drink, a notebook). What can be
     * sold and its price in points is ADD's own (sale_items) — the
     * inventory package knows no prices. A sale is the payable of its
     * payment (purpose item_sale): it exists only once paid, and paying it
     * issues its lines from stock in the same transaction.
     *
     * Also links a plan's resource types (printing) to the stock they use
     * up (paper), so recording consumption at reception issues it.
     */
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->unique()->constrained('inv_items')->restrictOnDelete();
            $table->unsignedInteger('price_points');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('item_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // Where the stock was issued from (an inventory place).
            $table->string('location_type', 40);
            $table->unsignedBigInteger('location_id');
            $table->unsignedInteger('total_points');
            $table->foreignId('sold_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('item_sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_sale_id')->constrained('item_sales')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inv_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price_points');
            $table->unsignedInteger('line_points');
            $table->timestamps();
        });

        Schema::table('resource_types', function (Blueprint $table) {
            $table->foreignId('inventory_item_id')->nullable()->after('icon')->constrained('inv_items')->nullOnDelete();
            // Stock used per unit consumed (one page = one sheet).
            $table->decimal('stock_units_per_unit', 10, 3)->default(1)->after('inventory_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('resource_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_item_id');
            $table->dropColumn('stock_units_per_unit');
        });

        Schema::dropIfExists('item_sale_lines');
        Schema::dropIfExists('item_sales');
        Schema::dropIfExists('sale_items');
    }
};
