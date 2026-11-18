<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reception's manual wallet top-up needs to record which operator keyed
     * it and which physical/electronic channel the money came in through.
     * Both nullable — null for every existing row and for any future
     * non-reception-initiated transaction.
     */
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users');
            $table->string('payment_method', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('performed_by_user_id');
            $table->dropColumn('payment_method');
        });
    }
};
