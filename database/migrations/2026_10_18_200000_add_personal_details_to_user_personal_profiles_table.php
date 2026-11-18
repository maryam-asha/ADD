<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal details that belong to the person rather than to any one role:
 * HR fills them for employees (App\Domain\Hr\Adapters\AddPersonStore), all
 * optional. Not exposed or writable on the member surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_personal_profiles', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('gender');
            $table->char('nationality', 2)->nullable()->after('date_of_birth');
            $table->string('marital_status', 16)->nullable()->after('nationality');
            $table->string('personal_email')->nullable()->after('marital_status');
            $table->string('address', 500)->nullable()->after('personal_email');
        });
    }

    public function down(): void
    {
        Schema::table('user_personal_profiles', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'nationality', 'marital_status', 'personal_email', 'address']);
        });
    }
};
