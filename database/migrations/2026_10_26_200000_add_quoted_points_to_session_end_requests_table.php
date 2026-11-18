<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The price the member was shown when they asked to end
 * (GET member/coworking-sessions/current/checkout). The request's
 * `requested_at` is the time that price was worked out for, and reception
 * closes the session at exactly that time — so what the member agreed to is
 * what they are billed (docs/decisions/coworking-self-service-sessions.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_end_requests', function (Blueprint $table) {
            $table->unsignedInteger('quoted_points')->nullable()->after('requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('session_end_requests', function (Blueprint $table) {
            $table->dropColumn('quoted_points');
        });
    }
};
