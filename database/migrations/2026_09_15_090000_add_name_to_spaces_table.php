<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spaces had no human-readable identifier at all — only `space_type`,
     * a three-value enum. Any location string built from a space could
     * therefore say no more than "Meeting Room", making two rooms in the
     * same branch indistinguishable to a member. Translatable JSON, the
     * same shape as Branch::name / Building::name.
     */
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->json('name')->nullable()->after('building_id');
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
