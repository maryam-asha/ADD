<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `string` + a PHP backed enum cast rather than a MySQL ENUM — build plan
     * §A.4, enforced by Tests\Guards\NoNewMysqlEnumColumnsTest. The create
     * migration for this table is grandfathered into that guard's allowlist;
     * this one is not, and shouldn't be.
     *
     * The default backfills every row written before this column existed.
     * All of them came from the OTP-only flow, which had exactly one purpose:
     * registration. Application code always passes a purpose explicitly.
     */
    public function up(): void
    {
        Schema::table('otp_verifications', function (Blueprint $table) {
            $table->string('purpose')->default('registration')->after('provider');

            // verify() looks up the newest live code of a given purpose for a
            // phone on every attempt; `phone` alone is already indexed, but
            // the pair is what that query actually filters on.
            $table->index(['phone', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::table('otp_verifications', function (Blueprint $table) {
            $table->dropIndex(['phone', 'purpose']);
            $table->dropColumn('purpose');
        });
    }
};
