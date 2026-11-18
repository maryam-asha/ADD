<?php

namespace Add\Hr\Recruitment\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Services\EmploymentLifecycleService;
use Add\Hr\Payroll\Services\CompensationService;
use Add\Hr\Recruitment\Enums\OfferStatus;
use Add\Hr\Recruitment\Enums\RequisitionStatus;
use Add\Hr\Recruitment\Enums\StageType;
use Add\Hr\Recruitment\Models\Application;
use Add\Hr\Recruitment\Models\PipelineStage;
use Add\Hr\Support\HrException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns an accepted offer into an employee — through the one hire path
 * (EmploymentLifecycleService::hire), never a second one — with the
 * offer's pay as their first compensation.
 */
class RecruitmentService
{
    public function __construct(
        private readonly EmploymentLifecycleService $lifecycle,
        private readonly StageRecorder $stages,
        private readonly RequisitionService $requisitions,
    ) {}

    /**
     * @param  array<string, mixed>  $data  a hire payload (HireEmployeeRequest)
     */
    public function hire(Application $application, array $data, int|string|null $actorId): Employee
    {
        return DB::transaction(function () use ($application, $data, $actorId) {
            $application = Application::query()->with(['candidate', 'opening.requisition'])->lockForUpdate()->findOrFail($application->id);
            $offer = $application->liveOffer()->first();

            if (PipelineStage::typeOf($application->stage) !== StageType::Offer || $offer?->status !== OfferStatus::Accepted) {
                HrException::fail('hr::recruitment.offer_not_accepted');
            }

            $requisition = $application->opening->requisition;

            if ($requisition->status !== RequisitionStatus::Approved) {
                HrException::fail('hr::recruitment.requisition_not_open');
            }

            $employee = $this->lifecycle->hire($data, $actorId);

            if (! empty($offer->compensation) && config('hr.modules.payroll', false)) {
                app(CompensationService::class)->change($employee, CarbonImmutable::parse($data['hired_on']), $offer->compensation, 'hire', $actorId);
            }

            if ($application->candidate->employee_id === null) {
                $application->candidate->update(['employee_id' => $employee->id]);
            }

            $application->update(['employee_id' => $employee->id]);
            $this->stages->move($application, PipelineStage::codeOf(StageType::Hired), null, $actorId);
            $this->requisitions->fillIfComplete($requisition);

            HrAudit::record('candidate_hired', $application, ['employee_id' => $employee->id]);

            return $employee;
        });
    }
}
