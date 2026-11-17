<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The café may accept an order with fewer of an item than was ordered,
     * or without it. Each line keeps what the member ordered
     * (`ordered_quantity`) beside what is made (`quantity`, 0 for an item
     * taken off); the order records when it was changed and the wallet row
     * that gave back the difference, when it had been paid. Every line so
     * far was made as ordered.
     */
    public function up(): void
    {
        Schema::table('kitchen_order_lines', function (Blueprint $table) {
            $table->unsignedInteger('ordered_quantity')->nullable()->after('quantity');
        });

        DB::table('kitchen_order_lines')->update(['ordered_quantity' => DB::raw('quantity')]);

        Schema::table('kitchen_orders', function (Blueprint $table) {
            $table->timestamp('adjusted_at')->nullable();
            $table->foreignId('adjustment_refund_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kitchen_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adjustment_refund_transaction_id');
            $table->dropColumn('adjusted_at');
        });

        Schema::table('kitchen_order_lines', function (Blueprint $table) {
            $table->dropColumn('ordered_quantity');
        });
    }
};
