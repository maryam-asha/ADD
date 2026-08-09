<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A company can have more than one admin — same pattern as
     * `door_access_enabled`, no new table or relation needed. A company
     * admin can manage other members' `door_access_enabled` and
     * `is_admin` (CompanyPolicy::manageMembers); a regular member cannot.
     */
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('door_access_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
