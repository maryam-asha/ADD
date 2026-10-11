<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service providers (docs/decisions/service-providers.md): intermediaries
 * that book seats at ADD for their client companies. `provider_user.user_id`
 * is unique — an account works for one provider. `provider_requests` is the
 * approval queue; `result_id` is what an approval created, its meaning
 * following `type`. `company_invitations` parks an approved employee whose
 * phone has no account yet, until they sign up with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contract_ref');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('provider_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('provider_id')->nullable()->after('branch_id')
                ->constrained('providers')->nullOnDelete();
        });

        Schema::table('private_office_requests', function (Blueprint $table) {
            $table->foreignId('provider_id')->nullable()->after('converted_company_id')
                ->constrained('providers')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->after('provider_id')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->foreignId('provider_id')->nullable()->after('is_public')
                ->constrained('providers')->nullOnDelete();
        });

        Schema::create('provider_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'status']);
            $table->index(['status', 'type']);
        });

        Schema::create('company_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->boolean('door_access_enabled')->default(false);
            $table->foreignId('provider_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'phone']);
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invitations');
        Schema::dropIfExists('provider_requests');

        Schema::table('plans', fn (Blueprint $table) => $table->dropConstrainedForeignId('provider_id'));
        Schema::table('private_office_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('provider_id');
        });
        Schema::table('companies', fn (Blueprint $table) => $table->dropConstrainedForeignId('provider_id'));

        Schema::dropIfExists('provider_user');
        Schema::dropIfExists('providers');
    }
};
