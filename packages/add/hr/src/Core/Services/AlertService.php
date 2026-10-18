<?php

namespace Add\Hr\Core\Services;

use Add\Hr\Core\Data\Alert;
use Add\Hr\Core\Enums\AlertType;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\EmployeeDocument;
use Add\Hr\Training\Models\Certification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What HR must decide soon, computed on read for everyone employed today:
 * fixed-term contracts ending with no renewal, probations ending (or past
 * their end without a confirmation), documents expiring, expired or missing.
 * Windows come from config('hr.alerts').
 */
class AlertService
{
    public function __construct(private readonly DocumentCompliance $compliance) {}

    /**
     * @return list<Alert>
     */
    public function current(?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse($today ?? CarbonImmutable::today())->startOfDay();
        $day = $today->toDateString();
        $alerts = [];

        $employees = Employee::query()
            ->where('hired_on', '<=', $day)
            ->where(fn ($q) => $q->whereNull('terminated_on')->orWhere('terminated_on', '>=', $day))
            ->orderBy('id')
            ->get();

        foreach ($employees as $employee) {
            array_push(
                $alerts,
                ...$this->contractAlerts($employee, $today),
                ...$this->probationAlerts($employee, $today),
                ...$this->documentAlerts($employee, $today),
                ...$this->certificationAlerts($employee, $today),
            );
        }

        usort($alerts, fn (Alert $a, Alert $b) => [$a->dueOn, $a->employeeId] <=> [$b->dueOn, $b->employeeId]);

        return $alerts;
    }

    /**
     * @return list<Alert>
     */
    private function contractAlerts(Employee $employee, CarbonImmutable $today): array
    {
        // A termination already decides how the employment ends.
        if ($employee->terminated_on !== null) {
            return [];
        }

        $contract = $employee->currentContract($today);

        if ($contract?->ends_on === null || $contract->ends_on->gt($today->addDays((int) config('hr.alerts.contract_days')))) {
            return [];
        }

        $renewed = $employee->contracts()->where('starts_on', '>', $contract->ends_on->toDateString())->exists();

        return $renewed ? [] : [$this->alert(AlertType::ContractEnding, $employee, $contract->ends_on, $today, ['contract_id' => $contract->id])];
    }

    /**
     * @return list<Alert>
     */
    private function probationAlerts(Employee $employee, CarbonImmutable $today): array
    {
        $ends = $employee->probation_ends_on;

        if ($ends === null || $employee->confirmed_on !== null || $ends->gt($today->addDays((int) config('hr.alerts.probation_days')))) {
            return [];
        }

        return [$this->alert(AlertType::ProbationEnding, $employee, $ends, $today)];
    }

    /**
     * @return list<Alert>
     */
    private function documentAlerts(Employee $employee, CarbonImmutable $today): array
    {
        $status = $this->compliance->for($employee);
        $horizon = $today->addDays((int) config('hr.alerts.document_days'));
        $alerts = [];

        foreach ($status['missing'] as $type) {
            $alerts[] = $this->alert(AlertType::DocumentMissing, $employee, $today, $today, ['document_type' => $type->code]);
        }

        $expiredIds = array_map(fn (EmployeeDocument $doc) => $doc->id, $status['expired']);

        foreach ($status['expired'] as $document) {
            $alerts[] = $this->alert(AlertType::DocumentExpired, $employee, $document->expires_on, $today, [
                'document_id' => $document->id,
                'document_type' => $document->documentType->code,
            ]);
        }

        $current = $employee->documents()->with('documentType')->orderBy('id')->get()->keyBy('document_type_id');

        foreach ($current as $document) {
            if ($document->expires_on === null || in_array($document->id, $expiredIds, true) || $document->expires_on->gt($horizon)) {
                continue;
            }

            $alerts[] = $this->alert(AlertType::DocumentExpiring, $employee, $document->expires_on, $today, [
                'document_id' => $document->id,
                'document_type' => $document->documentType->code,
            ]);
        }

        return $alerts;
    }

    /**
     * The latest certification of each name: expired, or expiring within
     * alerts.certification_days. A renewed one replaces the old.
     *
     * @return list<Alert>
     */
    private function certificationAlerts(Employee $employee, CarbonImmutable $today): array
    {
        if (! config('hr.modules.training', false)) {
            return [];
        }

        $horizon = $today->addDays((int) config('hr.alerts.certification_days', 45));

        return Certification::query()->where('employee_id', $employee->id)->orderBy('issued_on')->orderBy('id')->get()
            ->keyBy(fn (Certification $certification) => json_encode($certification->name))
            ->filter(fn (Certification $certification) => $certification->expires_on !== null && $certification->expires_on->lte($horizon))
            ->map(fn (Certification $certification) => $this->alert(
                $certification->expires_on->lt($today) ? AlertType::CertificationExpired : AlertType::CertificationExpiring,
                $employee,
                $certification->expires_on,
                $today,
                ['certification_id' => $certification->id],
            ))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function alert(AlertType $type, Employee $employee, CarbonImmutable $dueOn, CarbonImmutable $today, array $meta = []): Alert
    {
        return new Alert(
            type: $type,
            employeeId: $employee->id,
            employeeNumber: $employee->employee_number,
            employeeName: $employee->name,
            dueOn: $dueOn,
            daysLeft: (int) $today->diffInDays($dueOn, false),
            meta: $meta,
        );
    }
}
