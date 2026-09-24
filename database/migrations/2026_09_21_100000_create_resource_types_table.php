<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin-manageable catalog of natural-unit resources a plan can grant a
 * quota of (e.g. "Printing" measured in pages, "Internet" measured in
 * hours) — see docs/decisions/resource-quota-design.md. Deliberately not
 * named `resources`/`Resource`: that name and admin route segment already
 * belong to App\Domain\Foundation\Models\Resource, unrelated physical
 * equipment metadata on a Space.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->json('name');
            $table->json('unit_label');
            $table->string('icon', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_types');
    }
};
