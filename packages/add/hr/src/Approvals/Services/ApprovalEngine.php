<?php

namespace Add\Hr\Approvals\Services;

use Add\Hr\Approvals\Enums\ApprovalStatus;
use Add\Hr\Approvals\Enums\ApproverType;
use Add\Hr\Approvals\Enums\DecisionSource;
use Add\Hr\Approvals\Enums\StepStatus;
use Add\Hr\Approvals\Enums\TimeoutAction;
use Add\Hr\Approvals\Models\ApprovalDelegation;
use Add\Hr\Approvals\Models\ApprovalFlow;
use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Approvals\Models\ApprovalStep;
use Add\Hr\Audit\HrAudit;
use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;
use Add\Hr\Support\LocalDay;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * The only writer of approval requests: submitting, deciding, withdrawing
 * and timing out. Every decision runs under the request's row lock and
 * calls the type's ApprovalHandler in the same transaction, so a check
 * that fails (balance, coverage) leaves the request exactly as it was.
 *
 * Separation of duties holds everywhere, overrides included: nobody
 * decides a request that is theirs or that they filed.
 */
class ApprovalEngine
{
    public function __construct(
        private readonly ApprovalTypes $types,
        private readonly ApproverResolver $resolver,
        private readonly HrNotifier $notifier,
    ) {}

    /**
     * Opens the request and activates its first step. Call inside the
     * transaction that creates the subject.
     */
    public function submit(Model $subject, string $type, Employee $employee, int|string|null $submittedBy): ApprovalRequest
    {
        $this->types->handler($type);

        return DB::transaction(function () use ($subject, $type, $employee, $submittedBy) {
            $request = ApprovalRequest::query()->create([
                'request_type' => $type,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'employee_id' => $employee->id,
                'submitted_by' => $submittedBy,
                'status' => ApprovalStatus::Pending,
            ]);
            $request->setRelation('employee', $employee);

            foreach ($this->stepsFor($type) as $index => $config) {
                $approverType = ApproverType::from($config['approver']);

                $request->steps()->create([
                    'sort' => $index + 1,
                    'approver_type' => $approverType,
                    'approver_employee_id' => $approverType === ApproverType::Employee ? ($config['employee_id'] ?? null) : null,
                    'approver_ability' => $approverType === ApproverType::Ability ? ($config['ability'] ?? null) : null,
                    'status' => StepStatus::Waiting,
                    'timeout_hours' => $config['timeout_hours'] ?? null,
                    'on_timeout' => isset($config['timeout_hours']) ? ($config['on_timeout'] ?? TimeoutAction::Escalate->value) : null,
                ]);
            }

            $this->activate($request, $request->steps()->first());

            HrAudit::record('approval_submitted', $request, ['type' => $type]);

            return $request;
        });
    }

    public function approve(ApprovalRequest $request, Authenticatable $user, ?string $comment = null, bool $override = false): ApprovalRequest
    {
        return $this->decide($request, $user, true, $comment, $override);
    }

    public function reject(ApprovalRequest $request, Authenticatable $user, ?string $comment = null, bool $override = false): ApprovalRequest
    {
        return $this->decide($request, $user, false, $comment, $override);
    }

    /**
     * Withdraws a pending request. The owning module calls this from its own
     * cancel action, which also updates the subject.
     */
    public function cancel(ApprovalRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $request = ApprovalRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! $request->isPending()) {
                HrException::fail('hr::approvals.not_pending');
            }

            $request->steps()->whereIn('status', [StepStatus::Pending->value, StepStatus::Waiting->value])
                ->update(['status' => StepStatus::Cancelled->value, 'due_at' => null]);

            $request->status = ApprovalStatus::Cancelled;
            $request->current_step = null;
            $request->decided_at = now();
            $request->save();

            HrAudit::record('approval_cancelled', $request);
        });
    }

    /**
     * How `$user` may decide the request's current step, or null when they
     * may not. Never true for the requester or whoever filed it.
     */
    public function decisionSource(ApprovalRequest $request, Authenticatable $user, bool $override = false): ?DecisionSource
    {
        $step = $request->pendingStep;

        if (! $request->isPending() || $step === null || $this->isOwnRequest($request, $user)) {
            return null;
        }

        if ($override) {
            return Gate::forUser($user)->allows(ApproverResolver::fallbackAbility()) ? DecisionSource::Override : null;
        }

        if ($step->approver_ability !== null) {
            return Gate::forUser($user)->allows($step->approver_ability) ? DecisionSource::Approver : null;
        }

        $me = Employee::forUser($user);

        if ($me === null || $step->approver_employee_id === null) {
            return null;
        }

        if ((int) $step->approver_employee_id === (int) $me->id) {
            return DecisionSource::Approver;
        }

        $delegated = ApprovalDelegation::query()
            ->where('delegator_employee_id', $step->approver_employee_id)
            ->where('delegate_employee_id', $me->id)
            ->activeOn(LocalDay::today(), $request->request_type)
            ->exists();

        return $delegated ? DecisionSource::Delegate : null;
    }

    /**
     * Pending requests `$user` may decide now: their own steps, steps
     * delegated to them, and steps asking an ability they hold.
     *
     * @return Builder<ApprovalRequest>
     */
    public function inbox(Authenticatable $user): Builder
    {
        $me = Employee::forUser($user);
        $userId = $user->getAuthIdentifier();
        $today = LocalDay::today();

        $abilities = ApprovalStep::query()
            ->where('status', StepStatus::Pending->value)
            ->whereNotNull('approver_ability')
            ->distinct()
            ->pluck('approver_ability')
            ->filter(fn (string $ability) => Gate::forUser($user)->allows($ability))
            ->values()
            ->all();

        $delegations = $me === null ? collect() : ApprovalDelegation::query()
            ->where('delegate_employee_id', $me->id)
            ->activeOn($today)
            ->get(['delegator_employee_id', 'request_type']);

        return ApprovalRequest::query()
            ->where('status', ApprovalStatus::Pending->value)
            ->where(fn ($q) => $q->whereNull('submitted_by')->orWhere('submitted_by', '!=', $userId))
            ->when($me !== null, fn ($q) => $q->where('employee_id', '!=', $me->id))
            ->whereHas('steps', function ($q) use ($me, $abilities, $delegations) {
                $q->where('status', StepStatus::Pending->value)->where(function ($q) use ($me, $abilities, $delegations) {
                    $q->whereIn('approver_ability', $abilities);

                    if ($me !== null) {
                        $q->orWhere('approver_employee_id', $me->id);
                    }

                    foreach ($delegations as $delegation) {
                        $q->orWhere(function ($q) use ($delegation) {
                            $q->where('approver_employee_id', $delegation->delegator_employee_id);

                            if ($delegation->request_type !== null) {
                                $q->whereHas('request', fn ($q) => $q->where('request_type', $delegation->request_type));
                            }
                        });
                    }
                });
            });
    }

    /**
     * Applies each overdue step's timeout action. Called hourly.
     */
    public function processTimeouts(): int
    {
        $overdue = ApprovalStep::query()
            ->where('status', StepStatus::Pending->value)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->pluck('approval_request_id');

        foreach ($overdue as $requestId) {
            $this->processTimeout($requestId);
        }

        return $overdue->count();
    }

    private function processTimeout(int $requestId): void
    {
        DB::transaction(function () use ($requestId) {
            $request = ApprovalRequest::query()->lockForUpdate()->findOrFail($requestId);
            $step = $request->pendingStep;

            if (! $request->isPending() || $step === null || $step->due_at === null || $step->due_at->isFuture()) {
                return;
            }

            match ($step->on_timeout) {
                TimeoutAction::Approve => $this->approveOnTimeout($request, $step),
                TimeoutAction::Reject => $this->applyDecision($request, $step, false, null, DecisionSource::Timeout, null),
                default => $this->escalate($request, $step),
            };
        });
    }

    /**
     * A timeout approval still passes the type's checks; when they refuse,
     * the step goes to HR instead of being approved anyway.
     */
    private function approveOnTimeout(ApprovalRequest $request, ApprovalStep $step): void
    {
        try {
            DB::transaction(fn () => $this->applyDecision($request, $step, true, null, DecisionSource::Timeout, null));
        } catch (HrException $e) {
            Log::info('[hr] timeout approval refused, escalating', ['request' => $request->id, 'reason' => $e->getMessage()]);
            $this->escalate($request, $step, toFallback: true);
        }
    }

    private function escalate(ApprovalRequest $request, ApprovalStep $step, bool $toFallback = false): void
    {
        if ($toFallback) {
            $step->approver_employee_id = null;
        }

        $this->resolver->escalate($step, $request);

        // A person gets a new deadline; HR (the fallback) has none, so an
        // escalation never loops.
        $step->due_at = $step->approver_employee_id !== null && $step->timeout_hours
            ? now()->addHours($step->timeout_hours)
            : null;
        $step->escalated_at = now();
        $step->save();

        HrAudit::record('approval_escalated', $request, ['step' => $step->sort]);
        $this->notifyApprover($request, $step, 'approval_escalated');
    }

    private function decide(ApprovalRequest $request, Authenticatable $user, bool $approve, ?string $comment, bool $override): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $user, $approve, $comment, $override) {
            $request = ApprovalRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! $request->isPending()) {
                HrException::fail('hr::approvals.not_pending');
            }

            if ($this->isOwnRequest($request, $user)) {
                HrException::fail('hr::approvals.own_request', [], 403);
            }

            $source = $this->decisionSource($request, $user, $override);

            if ($source === null) {
                HrException::fail('hr::approvals.not_your_step', [], 403);
            }

            $this->applyDecision($request, $request->pendingStep, $approve, $user->getAuthIdentifier(), $source, $comment);

            return $request->refresh();
        });
    }

    private function applyDecision(ApprovalRequest $request, ApprovalStep $step, bool $approve, int|string|null $userId, DecisionSource $source, ?string $comment): void
    {
        $handler = $this->types->handler($request->request_type);
        $subject = $request->subject;
        $next = $request->steps()->where('sort', '>', $step->sort)->where('status', StepStatus::Waiting->value)->first();

        if ($approve) {
            $handler->beforeApprove($subject, $request, $next === null);
        }

        $step->fill([
            'status' => $approve ? StepStatus::Approved : StepStatus::Rejected,
            'decided_by' => $userId,
            'decided_via' => $source,
            'comment' => $comment,
            'decided_at' => now(),
            'due_at' => null,
        ])->save();

        HrAudit::record($approve ? 'approval_step_approved' : 'approval_step_rejected', $request, [
            'step' => $step->sort,
            'via' => $source->value,
        ]);

        if ($approve && $next !== null) {
            $this->activate($request, $next);

            return;
        }

        if (! $approve) {
            $request->steps()->where('status', StepStatus::Waiting->value)->update(['status' => StepStatus::Cancelled->value]);
        }

        $request->status = $approve ? ApprovalStatus::Approved : ApprovalStatus::Rejected;
        $request->current_step = null;
        $request->decided_at = now();
        $request->save();

        $approve ? $handler->onApproved($subject, $request) : $handler->onRejected($subject, $request);

        $employee = $request->employee;
        $key = $approve ? 'request_approved' : 'request_rejected';
        DB::afterCommit(fn () => $this->notifier->notifyEmployee($employee, $key, ['request_id' => $request->id], self::target($request)));
    }

    /** @return array{target_type: string, target_id: string} the request a notice about it opens */
    private static function target(ApprovalRequest $request): array
    {
        return ['target_type' => 'hr_approval_request', 'target_id' => (string) $request->id];
    }

    private function activate(ApprovalRequest $request, ApprovalStep $step): void
    {
        $this->resolver->resolve($step, $request);

        $step->status = StepStatus::Pending;
        $step->due_at = $step->timeout_hours ? now()->addHours($step->timeout_hours) : null;
        $step->save();

        $request->current_step = $step->sort;
        $request->save();

        $this->notifyApprover($request, $step, 'approval_pending');
    }

    private function notifyApprover(ApprovalRequest $request, ApprovalStep $step, string $key): void
    {
        $approverId = $step->approver_employee_id;
        $ability = $step->approver_ability;
        $placeholders = ['request_id' => $request->id];
        $target = self::target($request);

        DB::afterCommit(function () use ($approverId, $ability, $key, $placeholders, $target) {
            if ($approverId !== null) {
                $approver = Employee::query()->find($approverId);

                if ($approver !== null) {
                    $this->notifier->notifyEmployee($approver, $key, $placeholders, $target);
                }

                return;
            }

            $this->notifier->notifyHolders($ability, $key, $placeholders, $target);
        });
    }

    private function isOwnRequest(ApprovalRequest $request, Authenticatable $user): bool
    {
        $userId = $user->getAuthIdentifier();

        return ($request->submitted_by !== null && (string) $request->submitted_by === (string) $userId)
            || ($request->employee?->user_id !== null && (string) $request->employee->user_id === (string) $userId);
    }

    /**
     * The active flow's steps, or the direct manager alone.
     *
     * @return list<array<string, mixed>>
     */
    private function stepsFor(string $type): array
    {
        $flow = ApprovalFlow::query()->where('request_type', $type)->where('is_active', true)->first();

        return $flow?->steps ?: [['approver' => ApproverType::DirectManager->value]];
    }
}
