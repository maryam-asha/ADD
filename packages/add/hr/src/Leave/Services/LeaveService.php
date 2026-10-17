<?php

namespace Add\Hr\Leave\Services;

use Add\Hr\Approvals\Services\ApprovalEngine;
use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Services\DocumentVault;
use Add\Hr\Leave\Enums\HalfDay;
use Add\Hr\Leave\Enums\LeaveRequestStatus;
use Add\Hr\Leave\Enums\LeaveUnit;
use Add\Hr\Leave\Models\LeavePolicy;
use Add\Hr\Leave\Models\LeaveRequest;
use Add\Hr\Leave\Models\LeaveType;
use Add\Hr\Support\HrException;
use Add\Hr\Support\PlainNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of leave requests: quoting, submitting (into the
 * approval engine) and cancelling. Approval and rejection land through
 * LeaveApprovalHandler.
 */
class LeaveService
{
    public const APPROVAL_TYPE = 'leave';

    public function __construct(
        private readonly WorkCalendar $calendar,
        private readonly LeaveLedger $ledger,
        private readonly LeavePolicyResolver $policies,
        private readonly ApprovalEngine $approvals,
        private readonly DocumentVault $vault,
    ) {}

    /**
     * How much a request would take, in the type's unit.
     *
     * @param  array<string, mixed>  $data  starts_on, ends_on, half_day?, hours?
     */
    public function quote(LeaveType $type, array $data): float
    {
        $from = CarbonImmutable::parse($data['starts_on']);
        $to = CarbonImmutable::parse($data['ends_on']);
        $halfDay = isset($data['half_day']) ? HalfDay::from($data['half_day']) : null;
        $hours = isset($data['hours']) ? (float) $data['hours'] : null;
        $singleDay = $from->isSameDay($to);

        if ($from->diffInDays($to) + 1 > (int) config('hr.leave.max_request_days')) {
            HrException::fail('hr::leave.too_long', ['days' => (int) config('hr.leave.max_request_days')]);
        }

        if ($type->unit === LeaveUnit::Hour) {
            if (! $singleDay || $hours === null || $hours <= 0) {
                HrException::fail('hr::leave.hours_on_one_day');
            }

            $this->assertWorkingDay($type, $from);

            return round($hours, 2);
        }

        if ($hours !== null) {
            HrException::fail('hr::leave.hours_on_one_day');
        }

        if ($halfDay !== null) {
            if (! $type->allows_half_day || ! $singleDay) {
                HrException::fail('hr::leave.half_day_not_allowed');
            }

            $this->assertWorkingDay($type, $from);

            return 0.5;
        }

        $days = $type->counts_calendar_days
            ? count($this->calendar->days($from, $to))
            : count($this->calendar->workingDays($from, $to));

        if ($days === 0) {
            HrException::fail('hr::leave.no_working_days');
        }

        return (float) $days;
    }

    /**
     * Files a request for the employee (by themselves, or by HR for them)
     * and sends it into its approval flow.
     *
     * @param  array<string, mixed>  $data  the SubmitLeaveRequest shape
     */
    public function submit(Employee $employee, array $data, ?UploadedFile $attachment, int|string|null $actorId): LeaveRequest
    {
        return DB::transaction(function () use ($employee, $data, $attachment, $actorId) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $type = LeaveType::query()->findOrFail($data['leave_type_id']);
            $from = CarbonImmutable::parse($data['starts_on']);
            $to = CarbonImmutable::parse($data['ends_on']);

            if (! $type->is_active) {
                HrException::fail('hr::leave.type_inactive');
            }

            if (! $employee->isEmployedOn($from) || ! $employee->isEmployedOn($to)) {
                HrException::fail('hr::leave.not_employed_on_dates');
            }

            $amount = $this->quote($type, $data);

            if ($employee->leaveRequests()->live()->overlapping($from, $to)->exists()) {
                HrException::fail('hr::leave.overlap');
            }

            if ($type->tracks_balance) {
                $this->assertAvailable($employee, $type, $amount, $this->policyOrFail($employee, $type, $from));
            }

            if ($type->requiresAttachment() && $attachment === null) {
                HrException::fail('hr::leave.attachment_required');
            }

            $document = $attachment === null || ! $type->requiresAttachment()
                ? null
                : $this->vault->store($employee, $type->attachmentType, $attachment, ['notes' => 'leave'], $actorId);

            $request = $employee->leaveRequests()->create([
                'leave_type_id' => $type->id,
                'starts_on' => $from,
                'ends_on' => $to,
                'half_day' => $data['half_day'] ?? null,
                'hours' => $type->unit === LeaveUnit::Hour ? $amount : null,
                'amount' => $amount,
                'reason' => $data['reason'] ?? null,
                'document_id' => $document?->id,
                'status' => LeaveRequestStatus::Pending,
                'submitted_by' => $actorId,
            ]);

            $approval = $this->approvals->submit($request, self::APPROVAL_TYPE, $employee, $actorId);
            $request->approval_request_id = $approval->id;
            $request->save();

            HrAudit::record('leave_requested', $request, ['amount' => $request->amount]);

            return $request;
        });
    }

    /**
     * A pending request is withdrawn; an approved one may be cancelled until
     * its first day, and its days come back.
     */
    public function cancel(LeaveRequest $request, int|string|null $actorId): void
    {
        DB::transaction(function () use ($request, $actorId) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->id);

            match ($request->status) {
                LeaveRequestStatus::Pending => $this->approvals->cancel($request->approval),
                LeaveRequestStatus::Approved => $this->reverseApproved($request, $actorId),
                default => HrException::fail('hr::leave.not_cancellable'),
            };

            $request->status = LeaveRequestStatus::Cancelled;
            $request->cancelled_at = now();
            $request->cancelled_by = $actorId;
            $request->save();

            HrAudit::record('leave_cancelled', $request);
        });
    }

    /**
     * A termination ends what the employee will no longer take: everything
     * pending, and approved leave starting after the last working day.
     */
    public function cancelAfterTermination(Employee $employee): void
    {
        if ($employee->terminated_on === null) {
            return;
        }

        $employee->leaveRequests()
            ->where(fn ($q) => $q->where('status', LeaveRequestStatus::Pending->value)
                ->orWhere(fn ($q) => $q->where('status', LeaveRequestStatus::Approved->value)
                    ->where('starts_on', '>', $employee->terminated_on->toDateString())))
            ->get()
            ->each(function (LeaveRequest $request) {
                if ($request->status === LeaveRequestStatus::Approved) {
                    // Its first day is after the last working day, but may
                    // already be today or past for a back-dated termination.
                    $this->ledger->reverse($request);
                    $request->forceFill(['status' => LeaveRequestStatus::Cancelled, 'cancelled_at' => now()])->save();

                    return;
                }

                $this->approvals->cancel($request->approval);
                $request->forceFill(['status' => LeaveRequestStatus::Cancelled, 'cancelled_at' => now()])->save();
            });
    }

    /**
     * The request fits in what is left: balance today, minus what pending
     * requests already hold, plus how far below zero the policy allows.
     */
    public function assertAvailable(Employee $employee, LeaveType $type, float $amount, LeavePolicy $policy, ?int $exceptRequestId = null): void
    {
        $available = $this->ledger->balance($employee, $type) - $this->ledger->pending($employee, $type, $exceptRequestId);

        if ($amount > $available + (float) $policy->max_negative + 0.001) {
            HrException::fail('hr::leave.insufficient_balance', ['available' => PlainNumber::of($available)]);
        }
    }

    public function policyOrFail(Employee $employee, LeaveType $type, CarbonImmutable $on): LeavePolicy
    {
        return $this->policies->for($employee, $type, $on) ?? HrException::fail('hr::leave.not_eligible');
    }

    /**
     * Each active type the employee may request today, with what is left.
     * Types without a balance show none.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function balances(Employee $employee): Collection
    {
        $today = CarbonImmutable::today();

        return LeaveType::query()->where('is_active', true)->orderBy('id')->get()
            ->map(function (LeaveType $type) use ($employee, $today) {
                $policy = $type->tracks_balance ? $this->policies->for($employee, $type, $today) : null;

                if ($type->tracks_balance && $policy === null) {
                    return null;
                }

                $balance = $type->tracks_balance ? $this->ledger->balance($employee, $type) : null;
                $pending = $this->ledger->pending($employee, $type);

                return [
                    'leave_type' => ['id' => $type->id, 'code' => $type->code, 'name' => $type->name, 'unit' => $type->unit->value],
                    'tracks_balance' => $type->tracks_balance,
                    'balance' => PlainNumber::of($balance),
                    'pending' => PlainNumber::of($pending),
                    'available' => $balance === null ? null : PlainNumber::of($balance - $pending),
                    'max_negative' => $policy?->max_negative,
                ];
            })
            ->filter()
            ->values();
    }

    private function reverseApproved(LeaveRequest $request, int|string|null $actorId): void
    {
        if ($request->starts_on->toDateString() <= CarbonImmutable::today()->toDateString()) {
            HrException::fail('hr::leave.already_started');
        }

        $this->ledger->reverse($request, $actorId);
    }

    private function assertWorkingDay(LeaveType $type, CarbonImmutable $day): void
    {
        if (! $type->counts_calendar_days && ! $this->calendar->isWorkingDay($day)) {
            HrException::fail('hr::leave.no_working_days');
        }
    }
}
