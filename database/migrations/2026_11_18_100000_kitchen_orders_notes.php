<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two notes on an order, both optional. `member_note`: anything the
     * member adds when sending it, seen by the member, reception and the
     * kitchen. `reception_note`: what reception adds when letting an order
     * through — where the member is sitting, or any other detail — seen by
     * the dashboard only, never by the member. Neither is `delivery_note`,
     * which is the place itself when the delivery is a written one.
     */
    public function up(): void
    {
        Schema::table('kitchen_orders', function (Blueprint $table) {
            $table->string('member_note', 500)->nullable()->after('delivery_note');
            $table->string('reception_note', 500)->nullable()->after('member_note');
        });
    }

    public function down(): void
    {
        Schema::table('kitchen_orders', function (Blueprint $table) {
            $table->dropColumn(['member_note', 'reception_note']);
        });
    }
};
