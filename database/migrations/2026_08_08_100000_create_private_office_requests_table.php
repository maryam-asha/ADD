<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The private-office pipeline: request -> quote -> signed contract ->
     * company account (PRD §5.3, decision from build plan Phase 2).
     * `converted_company_id` is added in a later migration once `companies`
     * exists — the two tables reference each other, so the FK can't be
     * declared on either one first.
     */
    public function up(): void
    {
        Schema::create('private_office_requests', function (Blueprint $table) {
            $table->id();
            $table->string('prospect_name');
            $table->string('contact');
            $table->string('status', 20)->default('requested');
            $table->string('quote_ref')->nullable();
            $table->string('contract_ref')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('private_office_requests');
    }
};
