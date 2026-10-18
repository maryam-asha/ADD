<?php

namespace Add\Hr\Approvals\Contracts;

use Add\Hr\Approvals\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * What a request type (leave, overtime, ...) does at each decision. The
 * module that owns the subject registers one with ApprovalTypes; the engine
 * calls it under the request's row lock, inside the decision's transaction.
 */
interface ApprovalHandler
{
    /**
     * Re-check what may have changed since submission (balance, coverage,
     * ...). Throw an HrException to refuse the approval with its reason.
     * `$final` is true on the last step, when the approval takes effect.
     */
    public function beforeApprove(Model $subject, ApprovalRequest $request, bool $final): void;

    /**
     * The last step approved.
     */
    public function onApproved(Model $subject, ApprovalRequest $request): void;

    /**
     * Any step rejected.
     */
    public function onRejected(Model $subject, ApprovalRequest $request): void;

    /**
     * What the approver sees in their inbox about the subject.
     *
     * @return array<string, mixed>
     */
    public function summary(Model $subject): array;
}
