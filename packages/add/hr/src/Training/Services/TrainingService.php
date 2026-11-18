<?php

namespace Add\Hr\Training\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Contracts\HrClock;
use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;
use Add\Hr\Support\LocalDay;
use Add\Hr\Training\Enums\EnrollmentStatus;
use Add\Hr\Training\Enums\SessionStatus;
use Add\Hr\Training\Models\Certification;
use Add\Hr\Training\Models\Enrollment;
use Add\Hr\Training\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sessions and their enrollments. HR enrolls anyone; employees enroll
 * themselves only where the session allows it, before it starts and while
 * seats remain. Completing a session completes its enrollments (but the
 * no-shows) and grants the course's certification.
 */
class TrainingService
{
    public function __construct(
        private readonly HrNotifier $notifier,
        private readonly HrClock $clock,
    ) {}

    public function enroll(TrainingSession $session, Employee $employee, int|string|null $actorId, bool $self): Enrollment
    {
        return DB::transaction(function () use ($session, $employee, $actorId, $self) {
            $session = TrainingSession::query()->with('course')->lockForUpdate()->findOrFail($session->id);
            $today = $this->today();
            if ($session->status !== SessionStatus::Scheduled) {
                HrException::fail('hr::training.session_not_scheduled');
            }
            if ($self && (! $session->self_enrollment || $session->starts_on->lte($today))) {
                HrException::fail('hr::training.self_enrollment_closed');
            }
            if (! $employee->isEmployedOn($today)) {
                HrException::fail('hr::training.not_employed');
            }
            $existing = $session->enrollments()->where('employee_id', $employee->id)->first();
            if ($existing !== null && $existing->status !== EnrollmentStatus::Cancelled) {
                HrException::fail('hr::training.already_enrolled');
            }
            if ($session->capacity !== null && $session->seatsTaken() >= $session->capacity) {
                HrException::fail('hr::training.session_full');
            }

            $attributes = ['status' => EnrollmentStatus::Enrolled, 'enrolled_by' => $actorId, 'completed_on' => null, 'score' => null];
            $enrollment = $existing === null
                ? $session->enrollments()->create(['employee_id' => $employee->id, ...$attributes])
                : tap($existing)->update($attributes);
            HrAudit::record('training_enrolled', $enrollment, ['self' => $self]);

            if (! $self) {
                $placeholders = $this->placeholders($session);
                DB::afterCommit(fn () => $this->notifier->notifyEmployee($employee, 'training_enrolled', $placeholders, $this->target($session)));
            }

            return $enrollment;
        });
    }

    /**
     * Employees cancel their own before the session starts; HR any time
     * while it is scheduled.
     */
    public function cancelEnrollment(Enrollment $enrollment, bool $self): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $self) {
            $enrollment = Enrollment::query()->with('session')->lockForUpdate()->findOrFail($enrollment->id);
            if ($enrollment->status !== EnrollmentStatus::Enrolled || $enrollment->session->status !== SessionStatus::Scheduled) {
                HrException::fail('hr::training.enrollment_not_active');
            }
            if ($self && $enrollment->session->starts_on->lte($this->today())) {
                HrException::fail('hr::training.session_started');
            }

            $enrollment->update(['status' => EnrollmentStatus::Cancelled]);
            HrAudit::record('training_enrollment_cancelled', $enrollment, ['self' => $self]);

            return $enrollment;
        });
    }

    public function cancelSession(TrainingSession $session): TrainingSession
    {
        return DB::transaction(function () use ($session) {
            $session = TrainingSession::query()->with('course')->lockForUpdate()->findOrFail($session->id);
            if ($session->status !== SessionStatus::Scheduled) {
                HrException::fail('hr::training.session_not_scheduled');
            }

            $enrolled = $session->enrollments()->where('status', EnrollmentStatus::Enrolled->value)->with('employee')->get();
            $session->enrollments()->where('status', EnrollmentStatus::Enrolled->value)->update(['status' => EnrollmentStatus::Cancelled->value]);
            $session->update(['status' => SessionStatus::Cancelled]);
            HrAudit::record('training_session_cancelled', $session, ['enrollments' => $enrolled->count()]);

            $placeholders = $this->placeholders($session);
            $target = $this->target($session);
            DB::afterCommit(fn () => $enrolled->each(fn (Enrollment $enrollment) => $this->notifier->notifyEmployee($enrollment->employee, 'training_cancelled', $placeholders, $target)));

            return $session;
        });
    }

    /**
     * Once it has ended: every enrollment still enrolled is completed on the
     * last day, except `$noShowIds`; `$scores` are by enrollment id.
     *
     * @param  list<int>  $noShowIds
     * @param  array<int, string>  $scores
     */
    public function completeSession(TrainingSession $session, array $noShowIds, array $scores, int|string|null $actorId): TrainingSession
    {
        return DB::transaction(function () use ($session, $noShowIds, $scores, $actorId) {
            $session = TrainingSession::query()->with('course')->lockForUpdate()->findOrFail($session->id);
            if ($session->status !== SessionStatus::Scheduled) {
                HrException::fail('hr::training.session_not_scheduled');
            }
            if ($session->ends_on->gt($this->today())) {
                HrException::fail('hr::training.session_not_ended');
            }

            foreach ($session->enrollments()->where('status', EnrollmentStatus::Enrolled->value)->get() as $enrollment) {
                if (in_array($enrollment->id, $noShowIds, true)) {
                    $enrollment->update(['status' => EnrollmentStatus::NoShow]);

                    continue;
                }

                $enrollment->update(['status' => EnrollmentStatus::Completed, 'completed_on' => $session->ends_on, 'score' => $scores[$enrollment->id] ?? null]);
                $this->certify($session, $enrollment, $actorId);
            }

            $session->update(['status' => SessionStatus::Completed]);
            HrAudit::record('training_session_completed', $session, ['no_shows' => count($noShowIds)]);

            return $session;
        });
    }

    private function certify(TrainingSession $session, Enrollment $enrollment, int|string|null $actorId): void
    {
        $course = $session->course;
        if ($course->certification_name === null) {
            return;
        }

        Certification::query()->create([
            'employee_id' => $enrollment->employee_id,
            'course_id' => $course->id,
            'enrollment_id' => $enrollment->id,
            'name' => $course->certification_name,
            'issuer' => $course->provider,
            'issued_on' => $session->ends_on,
            'expires_on' => $course->certification_valid_months === null ? null : $session->ends_on->addMonths($course->certification_valid_months),
            'created_by' => $actorId,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function placeholders(TrainingSession $session): array
    {
        return [
            'course_ar' => $session->course->title['ar'] ?? '',
            'course_en' => $session->course->title['en'] ?? $session->course->title['ar'] ?? '',
            'starts_on' => $session->starts_on->toDateString(),
        ];
    }

    /**
     * @return array{target_type: string, target_id: string} the session a notice about it opens
     */
    private function target(TrainingSession $session): array
    {
        return ['target_type' => 'hr_training_session', 'target_id' => (string) $session->id];
    }

    private function today(): CarbonImmutable
    {
        return LocalDay::today($this->clock);
    }
}
