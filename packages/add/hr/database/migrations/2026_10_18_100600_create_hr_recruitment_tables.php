<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_pipeline_stages', function (Blueprint $table) {
            $table->id();
            // Applications store the code.
            $table->string('code', 32)->unique();
            $table->json('name');
            $table->unsignedSmallInteger('sort');
            // open | offer | hired | rejected | withdrawn; only `open` stages
            // are added or edited.
            $table->string('type', 16);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('hr_pipeline_stages')->insert(array_map(fn (array $stage) => [
            'code' => $stage[0],
            'name' => json_encode(['ar' => $stage[1], 'en' => $stage[2]], JSON_UNESCAPED_UNICODE),
            'sort' => $stage[3],
            'type' => $stage[4],
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            ['applied', 'تقدّم', 'Applied', 10, 'open'],
            ['screening', 'فرز', 'Screening', 20, 'open'],
            ['interview', 'مقابلة', 'Interview', 40, 'open'],
            // Open stages sort below 80 (the stage requests' limit), so
            // every one comes before the offer.
            ['offer', 'عرض عمل', 'Offer', 80, 'offer'],
            ['hired', 'تم التعيين', 'Hired', 90, 'hired'],
            ['rejected', 'مرفوض', 'Rejected', 100, 'rejected'],
            ['withdrawn', 'انسحب', 'Withdrawn', 110, 'withdrawn'],
        ]));

        Schema::create('hr_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_unit_id')->constrained('hr_org_units')->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
            $table->foreignId('job_grade_id')->nullable()->constrained('hr_job_grades')->nullOnDelete();
            $table->json('title');
            $table->unsignedSmallInteger('headcount')->default(1);
            $table->string('employment_type', 16);
            // new_position | replacement
            $table->string('reason', 16);
            $table->foreignId('replaces_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            // The approval requester.
            $table->foreignId('hiring_manager_id')->constrained('hr_employees')->restrictOnDelete();
            $table->decimal('budget_min', 14, 4)->nullable();
            $table->decimal('budget_max', 14, 4)->nullable();
            $table->string('budget_currency', 3)->nullable();
            $table->text('justification')->nullable();
            // pending | approved | rejected | filled | closed | cancelled
            $table->string('status', 16);
            $table->foreignId('approval_request_id')->nullable()->constrained('hr_approval_requests')->nullOnDelete();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('hiring_manager_id');
        });

        Schema::create('hr_job_openings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('hr_requisitions')->restrictOnDelete();
            $table->json('title');
            $table->json('description')->nullable();
            $table->string('employment_type', 16);
            $table->foreignId('work_location_id')->nullable()->constrained('hr_work_locations')->nullOnDelete();
            // public (careers page and employees) | internal (employees only)
            $table->string('visibility', 16);
            // draft | open | closed
            $table->string('status', 16);
            // The last day applications are taken.
            $table->date('closes_on')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'visibility']);
        });

        Schema::create('hr_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            // Lower-cased: one candidate per email.
            $table->string('email', 191)->nullable()->unique();
            $table->string('phone', 32)->nullable();
            // careers | internal | referral | agency | direct
            $table->string('source', 16);
            // An employee is one candidate however they apply.
            $table->foreignId('employee_id')->nullable()->unique()->constrained('hr_employees')->nullOnDelete();
            $table->timestamp('consent_at')->nullable();
            // Applying or moving through the pipeline; the purge counts from here.
            $table->timestamp('last_activity_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_opening_id')->constrained('hr_job_openings')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('hr_candidates')->cascadeOnDelete();
            // A pipeline stage code.
            $table->string('stage', 32);
            $table->timestamp('stage_changed_at')->nullable();
            $table->string('source', 16);
            $table->text('cover_letter')->nullable();
            $table->foreignId('referred_by_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->string('rejection_reason', 500)->nullable();
            // The employee this application was hired as.
            $table->foreignId('employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            // The CV, encrypted on the private documents disk.
            $table->string('cv_path')->nullable();
            $table->string('cv_name')->nullable();
            $table->string('cv_mime', 127)->nullable();
            $table->unsignedInteger('cv_size')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['job_opening_id', 'candidate_id']);
            $table->index(['stage', 'stage_changed_at']);
        });

        Schema::create('hr_application_stage_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('hr_applications')->cascadeOnDelete();
            $table->string('from_stage', 32)->nullable();
            $table->string('to_stage', 32);
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('hr_applications')->cascadeOnDelete();
            // UTC; entered and shown in the organisation's timezone.
            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            // in_person | video | phone
            $table->string('mode', 16);
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            // scheduled | completed | cancelled
            $table->string('status', 16);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('scheduled_at');
        });

        Schema::create('hr_interview_interviewers', function (Blueprint $table) {
            $table->foreignId('interview_id')->constrained('hr_interviews')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();

            $table->primary(['interview_id', 'employee_id']);
        });

        Schema::create('hr_interview_scorecards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id')->constrained('hr_interviews')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            // strong_yes | yes | no | strong_no
            $table->string('recommendation', 16);
            // criterion => 1..5
            $table->json('ratings')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['interview_id', 'employee_id']);
        });

        Schema::create('hr_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('hr_applications')->cascadeOnDelete();
            $table->date('start_on');
            $table->date('expires_on')->nullable();
            // Pay lines applied as the first compensation on hire:
            // [{pay_component_id, amount, currency}].
            $table->json('compensation')->nullable();
            $table->text('terms')->nullable();
            // pending | approved | rejected | accepted | declined | withdrawn
            $table->string('status', 16);
            $table->foreignId('approval_request_id')->nullable()->constrained('hr_approval_requests')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->string('response_note', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_offers');
        Schema::dropIfExists('hr_interview_scorecards');
        Schema::dropIfExists('hr_interview_interviewers');
        Schema::dropIfExists('hr_interviews');
        Schema::dropIfExists('hr_application_stage_changes');
        Schema::dropIfExists('hr_applications');
        Schema::dropIfExists('hr_candidates');
        Schema::dropIfExists('hr_job_openings');
        Schema::dropIfExists('hr_requisitions');
        Schema::dropIfExists('hr_pipeline_stages');
    }
};
