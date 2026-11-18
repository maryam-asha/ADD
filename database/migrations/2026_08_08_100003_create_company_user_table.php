<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `door_access_enabled` is the per-member flag for the company's shared
     * door code — the only scoped capability in the app (D.8, see
     * docs/decisions/rbac-scoping.md). Deliberately not a scope_type/
     * scope_id system: CompanyPolicy::useDoorAccess() checks membership in
     * this table plus this one flag, nothing more general. Carries its own
     * `id` (rather than being a bare composite-key pivot) so audit_logs and
     * the policy have a single row to point at.
     */
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('door_access_enabled')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_user');
    }
};
