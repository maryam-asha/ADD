<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_items', function (Blueprint $table) {
            // A supplier's code or one generated here (EAN-13, prefix 2).
            $table->string('barcode', 32)->nullable()->unique()->after('sku');
            // The URL the host's upload returned.
            $table->string('image_url', 2048)->nullable()->after('description');
        });

        // Packs of the main unit: "1 box = 6 pieces". The ledger always
        // counts in the main unit; these only convert what is typed.
        Schema::create('inv_item_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inv_items')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('inv_units')->restrictOnDelete();
            $table->decimal('factor', 14, 4);
            $table->timestamps();

            $table->unique(['item_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_item_units');

        Schema::table('inv_items', function (Blueprint $table) {
            $table->dropUnique(['barcode']);
            $table->dropColumn(['barcode', 'image_url']);
        });
    }
};
