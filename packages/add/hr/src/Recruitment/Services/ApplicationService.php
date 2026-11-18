<?php

namespace Add\Hr\Recruitment\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Contracts\HrClock;
use Add\Hr\Recruitment\Enums\CandidateSource;
use Add\Hr\Recruitment\Enums\StageType;
use Add\Hr\Recruitment\Models\Application;
use Add\Hr\Recruitment\Models\Candidate;
use Add\Hr\Recruitment\Models\JobOpening;
use Add\Hr\Recruitment\Models\PipelineStage;
use Add\Hr\Support\HrException;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Applications, whichever way they come (the careers page, an employee,
 * HR), and their moves through the open stages. The offer and hired
 * stages are reached only by making an offer and by hiring.
 */
class ApplicationService
{
    public function __construct(
        private readonly CvVault $cvs,
        private readonly StageRecorder $stages,
        private readonly OfferService $offers,
        private readonly HrClock $clock,
    ) {}

    /**
     * @param  array{name?: string, email?: ?string, phone?: ?string, employee_id?: ?int}  $person
     * @param  array{cover_letter?: ?string, referred_by_employee_id?: ?int, consent?: bool}  $extra
     */
    public function apply(JobOpening $opening, array $person, ?UploadedFile $cv, CandidateSource $source, array $extra, int|string|null $actorId): Application
    {
        return DB::transaction(function () use ($opening, $person, $cv, $source, $extra, $actorId) {
            $opening = JobOpening::query()->lockForUpdate()->findOrFail($opening->id);

            if (! $opening->isAccepting(CarbonImmutable::now($this->clock->timezone()))) {
                HrException::fail('hr::recruitment.opening_closed');
            }

            $candidate = $this->candidateFor($person, $source, (bool) ($extra['consent'] ?? false));

            if ($opening->applications()->where('candidate_id', $candidate->id)->exists()) {
                HrException::fail('hr::recruitment.already_applied');
            }

            $application = $opening->applications()->create([
                'candidate_id' => $candidate->id,
                'stage' => $this->firstStage(),
                'stage_changed_at' => now(),
                'source' => $source,
                'cover_letter' => $extra['cover_letter'] ?? null,
                'referred_by_employee_id' => $extra['referred_by_employee_id'] ?? null,
                'created_by' => $actorId,
                ...($cv === null ? [] : $this->cvs->store($cv)),
            ]);
            $application->stageChanges()->create(['from_stage' => null, 'to_stage' => $application->stage, 'changed_by' => $actorId]);
            $candidate->update(['last_activity_at' => now()]);

            HrAudit::record('application_received', $application, ['source' => $source->value]);

            return $application;
        });
    }

    public function move(Application $application, string $to, ?string $note, int|string|null $actorId): Application
    {
        return DB::transaction(function () use ($application, $to, $note, $actorId) {
            $application = $this->lockedOpen($application);
            $target = PipelineStage::query()->where('code', $to)->first();

            if ($target === null || $target->type !== StageType::Open || ! $target->is_active) {
                HrException::fail('hr::recruitment.stage_not_movable');
            }

            if ($application->stage !== $to) {
                $this->stages->move($application, $to, $note, $actorId);
            }

            return $application;
        });
    }

    public function reject(Application $application, ?string $reason, int|string|null $actorId): Application
    {
        return $this->end($application, StageType::Rejected, $reason, $actorId);
    }

    /**
     * The candidate withdrew.
     */
    public function withdraw(Application $application, ?string $note, int|string|null $actorId): Application
    {
        return $this->end($application, StageType::Withdrawn, $note, $actorId);
    }

    private function end(Application $application, StageType $type, ?string $note, int|string|null $actorId): Application
    {
        return DB::transaction(function () use ($application, $type, $note, $actorId) {
            $application = Application::query()->lockForUpdate()->findOrFail($application->id);

            if (! in_array(PipelineStage::typeOf($application->stage), [StageType::Open, StageType::Offer], true)) {
                HrException::fail('hr::recruitment.application_ended');
            }

            $this->offers->withdrawLive($application);

            if ($type === StageType::Rejected) {
                $application->update(['rejection_reason' => $note === null ? null : mb_substr($note, 0, 500)]);
            }

            $this->stages->move($application, PipelineStage::codeOf($type), $note, $actorId);
            HrAudit::record('application_'.$type->value, $application);

            return $application;
        });
    }

    /**
     * Locked, and in a stage it may be moved from: an open one, or the offer
     * stage once no offer is in play.
     */
    private function lockedOpen(Application $application): Application
    {
        $application = Application::query()->lockForUpdate()->findOrFail($application->id);
        $type = PipelineStage::typeOf($application->stage);

        if (! ($type === StageType::Open || ($type === StageType::Offer && $application->liveOffer()->doesntExist()))) {
            HrException::fail('hr::recruitment.application_ended');
        }

        return $application;
    }

    private function firstStage(): string
    {
        return (string) PipelineStage::query()->where('type', StageType::Open->value)->where('is_active', true)->orderBy('sort')->value('code');
    }

    /**
     * An employee is one candidate however they apply; anyone else is one
     * per email. An existing candidate's details are changed by HR only,
     * never by a new application.
     *
     * @param  array{name?: string, email?: ?string, phone?: ?string, employee_id?: ?int}  $person
     */
    private function candidateFor(array $person, CandidateSource $source, bool $consent): Candidate
    {
        $email = isset($person['email']) ? mb_strtolower(trim($person['email'])) : null;
        $existing = match (true) {
            isset($person['employee_id']) => Candidate::query()->where('employee_id', $person['employee_id'])->first(),
            $email !== null => Candidate::query()->where('email', $email)->first(),
            default => null,
        };

        if ($existing !== null) {
            if ($consent) {
                $existing->update(['consent_at' => now()]);
            }

            return $existing;
        }

        return Candidate::query()->create([
            'name' => $person['name'],
            'email' => $email,
            'phone' => $person['phone'] ?? null,
            'employee_id' => $person['employee_id'] ?? null,
            'source' => $source,
            'consent_at' => $consent ? now() : null,
        ]);
    }
}
