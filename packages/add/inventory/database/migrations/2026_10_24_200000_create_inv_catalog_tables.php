<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The package's own places, used only when the host binds no
        // LocationResolver of its own.
        Schema::create('inv_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('inv_locations')->restrictOnDelete();
            $table->string('code', 40)->nullable()->unique();
            $table->json('name');
            $table->timestamps();
        });

        Schema::create('inv_units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->json('name');
            // A ream can be split into sheets, a chair cannot.
            $table->boolean('allows_fractions')->default(false);
            $table->timestamps();
        });

        // `kind` decides what an item in it is: an asset tracked one by one,
        // or stock counted by quantity. A whole branch of the tree shares it.
        Schema::create('inv_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('inv_categories')->restrictOnDelete();
            $table->string('kind', 10);
            $table->json('name');
            $table->boolean('show_to_members')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        // A category's own fields; its items also answer every field of its
        // ancestors.
        Schema::create('inv_attribute_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('inv_categories')->cascadeOnDelete();
            $table->string('key', 40);
            $table->json('label');
            $table->string('type', 10);
            $table->json('options')->nullable();
            $table->string('unit', 20)->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'key']);
        });

        Schema::create('inv_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('inv_categories')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('inv_units')->restrictOnDelete();
            $table->string('sku', 40)->nullable()->unique();
            $table->json('name');
            $table->text('description')->nullable();
            $table->json('properties')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_items');
        Schema::dropIfExists('inv_attribute_definitions');
        Schema::dropIfExists('inv_categories');
        Schema::dropIfExists('inv_units');
        Schema::dropIfExists('inv_locations');
    }
};
