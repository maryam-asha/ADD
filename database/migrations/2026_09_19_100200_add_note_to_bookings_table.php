<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The member's optional note on the confirmation screen ("Meeting purpose,
 * special requests..."). Reception reads it when preparing the room.
 *
 * Additive and nullable, so every existing row and every caller that does
 * not send one is unaffected. Not an enum and not money, so it clears both
 * NoNewMysqlEnumColumnsTest and MoneyIsDecimalOnlyTest by construction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('note', 500)->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
