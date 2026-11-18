<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some resources are general/shared inventory that isn't tied to any
     * single space — the product owner chose the simplest option: no
     * location/assignment tracking at all, just `space_id = null` meaning
     * "untracked general inventory". The existing FK + cascadeOnDelete
     * behavior is unchanged for rows that do have a space_id set.
     */
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->foreignId('space_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->foreignId('space_id')->nullable(false)->change();
        });
    }
};
