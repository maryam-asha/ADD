<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_stock_movements', function (Blueprint $table) {
            // The rows one stock operation wrote (several lines at once).
            $table->uuid('operation_id')->nullable()->after('transfer_id')->index();
            // What the person typed when it was not the item's main unit
            // ("2 boxes"); `quantity` always holds the main-unit amount.
            $table->foreignId('entered_unit_id')->nullable()->after('operation_id')->constrained('inv_units')->restrictOnDelete();
            $table->decimal('entered_quantity', 14, 3)->nullable()->after('entered_unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('inv_stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entered_unit_id');
            $table->dropIndex(['operation_id']);
            $table->dropColumn(['operation_id', 'entered_quantity']);
        });
    }
};
