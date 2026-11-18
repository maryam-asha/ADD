<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->json('name');
            $table->boolean('is_required')->default(false);
            // Contract types the requirement applies to; null = all.
            $table->json('contract_types')->nullable();
            $table->boolean('has_expiry')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained('hr_document_types')->restrictOnDelete();
            // On the hr.documents.disk, encrypted; never a public path.
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            // sha256 of the original bytes.
            $table->char('checksum', 64);
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable()->index();
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'document_type_id']);
        });

        Schema::create('hr_document_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('hr_employee_documents')->restrictOnDelete();
            // Who asked for the link; null on the download itself, whose only
            // credential is the signature.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 16);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_document_accesses');
        Schema::dropIfExists('hr_employee_documents');
        Schema::dropIfExists('hr_document_types');
    }
};
