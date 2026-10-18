<?php

namespace Add\Hr\Recruitment\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Recruitment\Enums\StageType;
use Add\Hr\Recruitment\Models\Application;
use Add\Hr\Recruitment\Models\Candidate;
use Add\Hr\Recruitment\Models\PipelineStage;

/**
 * Candidates are kept only as long as they are useful: one never hired and
 * not heard from for hr.recruitment.retention_months is deleted with every
 * application, interview, offer and CV. Employees' candidate records are
 * never purged here.
 */
class CandidatePurge
{
    public function __construct(private readonly CvVault $cvs) {}

    public function run(): int
    {
        $months = config('hr.recruitment.retention_months', 24);

        if ($months === null) {
            return 0;
        }

        $before = now()->subMonths((int) $months);
        $hired = PipelineStage::codeOf(StageType::Hired);
        $count = 0;

        Candidate::query()
            ->whereNull('employee_id')
            ->where(fn ($q) => $q->where('last_activity_at', '<', $before)->orWhere(fn ($q) => $q->whereNull('last_activity_at')->where('created_at', '<', $before)))
            ->whereDoesntHave('applications', fn ($q) => $q->where('stage', $hired))
            ->chunkById(100, function ($candidates) use (&$count) {
                foreach ($candidates as $candidate) {
                    $candidate->applications()->pluck('cv_path')->each(fn (?string $path) => $this->cvs->delete($path));
                    $candidate->delete();
                    $count++;
                }
            });

        if ($count > 0) {
            HrAudit::record('candidates_purged', null, ['count' => $count]);
        }

        return $count;
    }
}
