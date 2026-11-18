<?php

namespace Add\Hr\Recruitment\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Contracts\HrClock;
use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Recruitment\Enums\InterviewStatus;
use Add\Hr\Recruitment\Enums\StageType;
use Add\Hr\Recruitment\Models\Application;
use Add\Hr\Recruitment\Models\Interview;
use Add\Hr\Recruitment\Models\InterviewScorecard;
use Add\Hr\Recruitment\Models\PipelineStage;
use Add\Hr\Support\HrException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Interviews of applications still in an open stage. Each interviewer is
 * told, and scores it on their own scorecard.
 */
class InterviewService
{
    public function __construct(
        private readonly HrNotifier $notifier,
        private readonly HrClock $clock,
    ) {}

    /**
     * `scheduled_at` is local wall-clock time, "Y-m-d H:i".
     *
     * @param  array<string, mixed>  $data
     */
    public function schedule(Application $application, array $data, int|string|null $actorId): Interview
    {
        return DB::transaction(function () use ($application, $data, $actorId) {
            $application = Application::query()->with(['candidate', 'opening'])->lockForUpdate()->findOrFail($application->id);

            if (PipelineStage::typeOf($application->stage) !== StageType::Open) {
                HrException::fail('hr::recruitment.application_ended');
            }

            $interview = $application->interviews()->create([
                'scheduled_at' => CarbonImmutable::createFromFormat('Y-m-d H:i', $data['scheduled_at'], $this->clock->timezone())->utc(),
                'duration_minutes' => $data['duration_minutes'] ?? 60,
                'mode' => $data['mode'],
                'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => InterviewStatus::Scheduled,
                'created_by' => $actorId,
            ]);
            $interview->interviewers()->sync(array_values(array_unique($data['interviewer_ids'])));
            HrAudit::record('interview_scheduled', $interview);

            $placeholders = [
                'candidate' => $application->candidate->name,
                'opening_ar' => $application->opening->title['ar'] ?? '',
                'opening_en' => $application->opening->title['en'] ?? $application->opening->title['ar'] ?? '',
                'at' => $interview->scheduled_at->copy()->setTimezone($this->clock->timezone())->format('Y-m-d H:i'),
            ];
            $interviewers = $interview->interviewers()->get();
            $target = ['target_type' => 'hr_application', 'target_id' => (string) $application->id];
            DB::afterCommit(fn () => $interviewers->each(fn (Employee $interviewer) => $this->notifier->notifyEmployee($interviewer, 'interview_scheduled', $placeholders, $target)));

            return $interview;
        });
    }

    public function setStatus(Interview $interview, InterviewStatus $status): Interview
    {
        if ($interview->status !== InterviewStatus::Scheduled) {
            HrException::fail('hr::recruitment.interview_not_scheduled');
        }

        $interview->update(['status' => $status]);
        HrAudit::record('interview_'.$status->value, $interview);

        return $interview;
    }

    /**
     * @param  array{recommendation: string, ratings?: array<string, int>, notes?: ?string}  $data
     */
    public function score(Interview $interview, Employee $interviewer, array $data): InterviewScorecard
    {
        if (! $interview->interviewers()->whereKey($interviewer->id)->exists()) {
            HrException::fail('hr::recruitment.not_interviewer', [], 403);
        }

        if ($interview->status === InterviewStatus::Cancelled) {
            HrException::fail('hr::recruitment.interview_cancelled');
        }

        return InterviewScorecard::query()->updateOrCreate(
            ['interview_id' => $interview->id, 'employee_id' => $interviewer->id],
            [
                'recommendation' => $data['recommendation'],
                'ratings' => $data['ratings'] ?? null,
                'notes' => $data['notes'] ?? null,
                'submitted_at' => now(),
            ],
        );
    }
}
