<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_org_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('hr_org_units')->restrictOnDelete();
            $table->string('code', 32)->nullable()->unique();
            $table->json('name');
            // FK added with hr_employees (next migration).
            $table->unsignedBigInteger('head_employee_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_job_grades', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->json('name');
            $table->unsignedSmallInteger('rank');
            $table->timestamps();
        });

        Schema::create('hr_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_unit_id')->constrained('hr_org_units')->restrictOnDelete();
            $table->foreignId('job_grade_id')->nullable()->constrained('hr_job_grades')->nullOnDelete();
            $table->string('code', 32)->nullable()->unique();
            $table->json('title');
            $table->unsignedSmallInteger('headcount')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_work_locations', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->string('address', 500)->nullable();
            // Optional link to a host entity (ADD: a Branch), set by the host.
            $table->nullableMorphs('locatable');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_work_locations');
        Schema::dropIfExists('hr_positions');
        Schema::dropIfExists('hr_job_grades');
        Schema::dropIfExists('hr_org_units');
    }
};
