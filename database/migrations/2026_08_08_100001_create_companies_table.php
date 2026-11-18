<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Created exclusively by operations after a contract is signed — never
     * self-service (PRD §5.1, §5.3). `branch_id` is required: a private
     * office is physically located at one branch.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name');
            $table->string('contract_ref');
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_from_request_id')->nullable()
                ->constrained('private_office_requests')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
