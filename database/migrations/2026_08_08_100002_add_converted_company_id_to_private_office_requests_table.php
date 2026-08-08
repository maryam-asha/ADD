<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Closes the loop with `companies.created_from_request_id` now that
     * `companies` exists. Set once, when the request reaches `contracted`.
     */
    public function up(): void
    {
        Schema::table('private_office_requests', function (Blueprint $table) {
            $table->foreignId('converted_company_id')->nullable()->after('contract_ref')
                ->constrained('companies')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('private_office_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_company_id');
        });
    }
};
