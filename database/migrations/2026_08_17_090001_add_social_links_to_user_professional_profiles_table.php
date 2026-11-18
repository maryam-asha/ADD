<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_professional_profiles', function (Blueprint $table) {
            $table->string('instagram_url')->nullable()->after('linkedin_url');
            $table->string('behance_url')->nullable()->after('instagram_url');
            $table->string('website_url')->nullable()->after('behance_url');
        });
    }

    public function down(): void
    {
        Schema::table('user_professional_profiles', function (Blueprint $table) {
            $table->dropColumn(['instagram_url', 'behance_url', 'website_url']);
        });
    }
};
