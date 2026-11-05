<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where stock is kept: a named store with someone responsible for
        // it. Stock quantities live only here; assets stay on the host's
        // places.
        Schema::create('inv_warehouses', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->string('code', 40)->nullable()->unique();
            // A host user (a staff account); no foreign key, like actor_id.
            $table->unsignedBigInteger('manager_id')->nullable()->index();
            $table->text('address')->nullable();
            $table->string('location_url', 2048)->nullable();
            $table->text('notes')->nullable();
            // Where it sits among the host's places (ADD: a branch), if
            // anywhere — an external store sits in none.
            $table->string('place_type', 40)->nullable();
            $table->unsignedBigInteger('place_id')->nullable();
            $table->string('item_scope', 10)->default('all');
            $table->boolean('allows_sales')->default(true);
            // At most one per place: where the host takes stock by default.
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['place_type', 'place_id']);
        });

        // The items a warehouse may hold when its scope is `selected`.
        Schema::create('inv_warehouse_items', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->constrained('inv_warehouses')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inv_items')->restrictOnDelete();

            $table->primary(['warehouse_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_warehouse_items');
        Schema::dropIfExists('inv_warehouses');
    }
};
