<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('otp_verifications', function (Blueprint $table) {
            $table->dropColumn(['reset_token_hash', 'reset_token_expires_at']);
            $table->dateTime('password_reset_authorized_at')->nullable()->after('verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('otp_verifications', function (Blueprint $table) {
            $table->dropColumn('password_reset_authorized_at');
            $table->string('reset_token_hash')->nullable()->after('verified_at');
            $table->dateTime('reset_token_expires_at')->nullable()->after('reset_token_hash');
        });
    }
};
