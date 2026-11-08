<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a grant was revoked: a booking whose code maintenance stopped gets a
 * new one when the space reopens, one staff stopped by hand does not. Null
 * on every row revoked before this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_grants', function (Blueprint $table) {
            $table->string('revoke_reason', 20)->nullable()->after('status');
            $table->foreignId('revoked_by')->nullable()->after('revoke_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable()->after('revoked_by');
        });
    }

    public function down(): void
    {
        Schema::table('access_grants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['revoke_reason', 'revoked_at']);
        });
    }
};
