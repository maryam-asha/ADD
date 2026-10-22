<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties a device's push token to the session it was registered on, so ending
 * that session (logout, password reset, block) also stops its pushes. Null for
 * rows registered before this existed, or from a session-authenticated caller.
 *
 * Also what the app reports about the device. `device_id` is an id the app
 * generates once per install (never a hardware identifier): it keeps one row
 * per install, so a token Firebase rotates replaces the old one instead of
 * piling up beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->foreignId('access_token_id')
                ->nullable()
                ->after('user_id')
                ->constrained('personal_access_tokens')
                ->nullOnDelete();
            $table->string('device_id', 100)->nullable()->after('token');
            $table->string('app_version', 50)->nullable()->after('platform');
            $table->string('build_number', 50)->nullable()->after('app_version');
            $table->string('os_version', 50)->nullable()->after('build_number');
            $table->string('device_model', 100)->nullable()->after('os_version');

            $table->unique(['user_id', 'device_id']);
            $table->index('device_id');
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'device_id']);
            $table->dropIndex(['device_id']);
            $table->dropColumn(['device_id', 'app_version', 'build_number', 'os_version', 'device_model']);
            $table->dropConstrainedForeignId('access_token_id');
        });
    }
};
