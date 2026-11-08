<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An asset category may hold assets with no body (a licence): its
        // assets sit nowhere, so an asset's place becomes optional.
        Schema::table('inv_categories', function (Blueprint $table) {
            $table->boolean('intangible')->default(false)->after('kind');
        });

        Schema::table('inv_assets', function (Blueprint $table) {
            $table->string('location_type', 40)->nullable()->change();
            $table->unsignedBigInteger('location_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('inv_assets', function (Blueprint $table) {
            $table->string('location_type', 40)->nullable(false)->change();
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
        });

        Schema::table('inv_categories', function (Blueprint $table) {
            $table->dropColumn('intangible');
        });
    }
};
