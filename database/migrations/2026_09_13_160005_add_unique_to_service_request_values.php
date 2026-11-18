<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the missing unique index on service_request_values.
 * The original migration created the table but the auto-generated index name
 * exceeded MySQL's 64-char limit, so it was skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_request_values', function (Blueprint $table) {
            $table->unique(
                ['service_request_id', 'service_type_field_id'],
                'srv_req_values_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('service_request_values', function (Blueprint $table) {
            $table->dropUnique('srv_req_values_unique');
        });
    }
};
