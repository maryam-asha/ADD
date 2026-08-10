<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manual polymorphic on owner_type/owner_id (Consent pattern — no FK
     * on owner_id, since the referenced table depends on owner_type; see
     * docs/decisions/wallet-subscription-ownership.md). Unique together:
     * every individual and every company has exactly one wallet. No
     * `balance` column — see
     * docs/decisions/phase-3-membership-plan-wallet-mechanics.md for why.
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 20);
            $table->unsignedBigInteger('owner_id');
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
