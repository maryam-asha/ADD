<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_review_templates', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            // Ratings run 1..scale_max.
            $table->unsignedTinyInteger('scale_max')->default(5);
            // [{code, name: {ar, en}, weight}]
            $table->json('competencies');
            // The share of the overall score that comes from goals (0-100).
            $table->unsignedTinyInteger('goals_weight')->default(50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_review_cycles', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->foreignId('review_template_id')->constrained('hr_review_templates')->restrictOnDelete();
            // The template as it was at launch; reviews read this, not the template.
            $table->json('template')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            // Employees of this unit and its sub-units; null: everyone.
            $table->foreignId('org_unit_id')->nullable()->constrained('hr_org_units')->nullOnDelete();
            // draft | goal_setting | self_review | manager_review | calibration | closed
            $table->string('status', 16);
            $table->date('goals_due_on')->nullable();
            $table->date('self_due_on')->nullable();
            $table->date('manager_due_on')->nullable();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('hr_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            // Null: a goal outside any review cycle.
            $table->foreignId('review_cycle_id')->nullable()->constrained('hr_review_cycles')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('metric')->nullable();
            $table->string('target')->nullable();
            // Its share of the employee's goals in the cycle (0-100).
            $table->unsignedTinyInteger('weight')->default(0);
            $table->unsignedTinyInteger('progress')->default(0);
            // active | completed | cancelled
            $table->string('status', 16);
            $table->date('due_on')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'review_cycle_id']);
        });

        Schema::create('hr_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_cycle_id')->constrained('hr_review_cycles')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            // open | self_submitted | manager_submitted | finalized
            $table->string('status', 24);
            $table->text('self_comment')->nullable();
            $table->timestamp('self_submitted_at')->nullable();
            $table->text('manager_comment')->nullable();
            $table->timestamp('manager_submitted_at')->nullable();
            // none | promotion | compensation_change | improvement_plan: suggested, never applied.
            $table->string('recommendation', 24)->nullable();
            $table->decimal('competency_score', 5, 2)->nullable();
            $table->decimal('goal_score', 5, 2)->nullable();
            $table->decimal('overall_score', 5, 2)->nullable();
            $table->decimal('calibrated_score', 5, 2)->nullable();
            $table->string('calibration_note', 500)->nullable();
            $table->unsignedTinyInteger('final_rating')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['review_cycle_id', 'employee_id']);
            $table->index('reviewer_id');
        });

        Schema::create('hr_review_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('hr_reviews')->cascadeOnDelete();
            // competency (item_key = its code) | goal (item_key = the goal's id)
            $table->string('kind', 16);
            $table->string('item_key', 64);
            $table->unsignedTinyInteger('self_rating')->nullable();
            $table->text('self_comment')->nullable();
            $table->unsignedTinyInteger('manager_rating')->nullable();
            $table->text('manager_comment')->nullable();
            $table->timestamps();

            $table->unique(['review_id', 'kind', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_review_answers');
        Schema::dropIfExists('hr_reviews');
        Schema::dropIfExists('hr_goals');
        Schema::dropIfExists('hr_review_cycles');
        Schema::dropIfExists('hr_review_templates');
    }
};
