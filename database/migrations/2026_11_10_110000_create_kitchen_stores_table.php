<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The one warehouse each branch names as its kitchen store: the menu is
     * what it holds, and every kitchen order draws from it.
     */
    public function up(): void
    {
        Schema::create('kitchen_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained('branches')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('inv_warehouses')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_stores');
    }
};
