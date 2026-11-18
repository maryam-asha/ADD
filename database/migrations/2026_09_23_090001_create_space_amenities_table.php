<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which `AmenityType` values are actually toggled on for one specific
     * space — the admin-facing switches, cross-checked on write against
     * that space's `space_type` config in `space_type_amenities`. Unlike
     * `Resource`, this never carries a quantity or an admin-authored name:
     * amenities are a fixed, known set, on or off. `string` + PHP backed
     * enum cast, not a MySQL ENUM (build plan §A.4). FK style matches
     * `resources.space_id` above.
     */
    public function up(): void
    {
        Schema::create('space_amenities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->string('amenity', 20);
            $table->timestamps();

            $table->unique(['space_id', 'amenity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('space_amenities');
    }
};
