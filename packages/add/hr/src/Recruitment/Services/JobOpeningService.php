<?php

namespace Add\Hr\Recruitment\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Recruitment\Enums\OpeningStatus;
use Add\Hr\Recruitment\Enums\RequisitionStatus;
use Add\Hr\Recruitment\Models\JobOpening;
use Add\Hr\Recruitment\Models\Requisition;
use Add\Hr\Support\HrException;

/**
 * Openings advertise approved requisitions only; one opens and closes by
 * hand, and closes by itself when its requisition ends.
 */
class JobOpeningService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Requisition $requisition, array $data, int|string|null $actorId): JobOpening
    {
        $this->assertHiring($requisition);

        $opening = $requisition->openings()->create([
            'title' => $data['title'] ?? $requisition->title,
            'description' => $data['description'] ?? null,
            'employment_type' => $data['employment_type'] ?? $requisition->employment_type,
            'work_location_id' => $data['work_location_id'] ?? null,
            'visibility' => $data['visibility'],
            'closes_on' => $data['closes_on'] ?? null,
            'status' => OpeningStatus::Draft,
            'created_by' => $actorId,
        ]);

        HrAudit::record('job_opening_created', $opening);

        return ($data['open'] ?? false) ? $this->open($opening) : $opening;
    }

    public function open(JobOpening $opening): JobOpening
    {
        $this->assertHiring($opening->requisition);

        if ($opening->status !== OpeningStatus::Open) {
            $opening->update(['status' => OpeningStatus::Open, 'opened_at' => now(), 'closed_at' => null]);
            HrAudit::record('job_opening_opened', $opening);
        }

        return $opening;
    }

    public function close(JobOpening $opening): JobOpening
    {
        if ($opening->status !== OpeningStatus::Closed) {
            $opening->update(['status' => OpeningStatus::Closed, 'closed_at' => now()]);
            HrAudit::record('job_opening_closed', $opening);
        }

        return $opening;
    }

    private function assertHiring(Requisition $requisition): void
    {
        if ($requisition->status !== RequisitionStatus::Approved) {
            HrException::fail('hr::recruitment.requisition_not_open');
        }
    }
}
