<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * docs/decisions/wallet-points-categorization.md — no rows for a
     * transaction means unrestricted (any member of the owning wallet can
     * spend it); rows present mean only those named users can. Plain
     * pivot, no dedicated Pivot class: nothing points at one specific row
     * here for audit purposes the way `company_user` needs to for
     * CompanyPolicy.
     */
    public function up(): void
    {
        Schema::create('wallet_transaction_allowed_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_transaction_id')->constrained('wallet_transactions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // Explicit, shorter name: Laravel's auto-generated name for this
            // pair exceeds MySQL's 64-character identifier limit.
            $table->unique(['wallet_transaction_id', 'user_id'], 'wallet_transaction_allowed_users_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transaction_allowed_users');
    }
};
