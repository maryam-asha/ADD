<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "My projects" looks members up by person; the unique key starts with the
 * project, so it cannot serve that query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_project_members', function (Blueprint $table) {
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('pm_project_members', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
    }
};
