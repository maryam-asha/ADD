<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a booking still owes when it closes is paid by the method the member
 * booked with (`chosen_payment_method`, a config/payments.php key; null on
 * bookings made before it was kept). `ready_announced_at` is when the app
 * was told a room booking's check-in window opened, so it is told once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('chosen_payment_method', 32)->nullable()->after('payment_method');
            $table->timestamp('ready_announced_at')->nullable()->after('checked_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['chosen_payment_method', 'ready_announced_at']);
        });
    }
};
