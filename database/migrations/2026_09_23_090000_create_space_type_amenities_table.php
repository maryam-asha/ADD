<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-editable config matrix: for a given `space_type`, which
     * `AmenityType` values are even selectable (SpaceTypeAmenityController).
     * `space_type` is a plain enum value here, not an FK — there is no
     * "SpaceType" table, same as everywhere else this enum is used.
     * `string` + PHP backed enum casts, not a MySQL ENUM (build plan §A.4).
     */
    public function up(): void
    {
        Schema::create('space_type_amenities', function (Blueprint $table) {
            $table->id();
            $table->string('space_type', 20);
            $table->string('amenity', 20);
            $table->timestamps();

            $table->unique(['space_type', 'amenity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('space_type_amenities');
    }
};
