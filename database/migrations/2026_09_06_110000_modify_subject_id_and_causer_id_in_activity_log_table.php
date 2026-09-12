<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Models with string primary keys (e.g. `Currency` with `code` = 'USD')
     * or UUID keys fail when Spatie Activitylog logs an action on them because
     * default `nullableMorphs` creates `subject_id` and `causer_id` as
     * unsigned big integers. Widening both columns to VARCHAR(255) supports
     * both integer and string/UUID keys.
     */
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->string('subject_id', 255)->nullable()->change();
            $table->string('causer_id', 255)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->unsignedBigInteger('subject_id')->nullable()->change();
            $table->unsignedBigInteger('causer_id')->nullable()->change();
        });
    }
};
