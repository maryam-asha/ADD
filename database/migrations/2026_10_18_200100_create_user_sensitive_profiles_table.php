<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's sensitive data, apart from every other profile so nothing that
 * loads a user or a profile can carry it by accident. Every value is
 * encrypted; it is read and written only through
 * App\Domain\Identity\Services\SensitiveProfileService (guarded by
 * tests/Guards/SensitiveProfileStaysContainedTest). A new sensitive field is
 * a new column here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sensitive_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Encrypted casts: ciphertext is far longer than the value.
            $table->text('national_id')->nullable();
            $table->text('bank_account')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sensitive_profiles');
    }
};
