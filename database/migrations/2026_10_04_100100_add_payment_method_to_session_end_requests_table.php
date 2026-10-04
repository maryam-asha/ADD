<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How the member chose to pay when they asked to end: a
     * config/payments.php key (`wallet`, `cash`, `paymera`), or null when the
     * app did not say. Reception reads it on confirming — points are spent
     * then, cash is taken at the desk, a card is paid in the app once the
     * closed session has its price
     * (docs/decisions/coworking-self-service-sessions.md).
     */
    public function up(): void
    {
        Schema::table('session_end_requests', function (Blueprint $table) {
            $table->string('payment_method', 32)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('session_end_requests', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
