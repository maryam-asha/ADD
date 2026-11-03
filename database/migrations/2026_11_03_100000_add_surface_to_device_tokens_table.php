<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which client a push token belongs to: the member app, or a dashboard
 * browser. Member notices go to the first, staff and provider notices to
 * the second, so a person who is both never gets one on the other's screen.
 * Every token registered so far came from the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->string('surface', 20)->default('app')->after('platform');
            $table->index(['user_id', 'surface']);
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'surface']);
            $table->dropColumn('surface');
        });
    }
};
