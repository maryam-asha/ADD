<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When an order reached reception's queue, for the reception window.
     * Measured until now from when it was paid or else sent, a card hold
     * switched to cash near its deadline reached the desk with almost none of
     * the window left. Orders already waiting there reached it when sent.
     */
    public function up(): void
    {
        Schema::table('kitchen_orders', function (Blueprint $table) {
            $table->timestamp('reached_reception_at')->nullable();

            $table->index(['status', 'reached_reception_at']);
        });

        DB::table('kitchen_orders')
            ->where('status', 'awaiting_reception')
            ->update(['reached_reception_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('kitchen_orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'reached_reception_at']);
            $table->dropColumn('reached_reception_at');
        });
    }
};
