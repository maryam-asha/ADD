<?php

namespace Add\Hr\Recruitment\Services;

use Add\Hr\Approvals\Contracts\ApprovalHandler;
use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Recruitment\Enums\OfferStatus;
use Add\Hr\Recruitment\Models\Offer;
use Add\Hr\Support\HrException;
use Illuminate\Database\Eloquent\Model;

class OfferApprovalHandler implements ApprovalHandler
{
    /**
     * @param  Offer  $subject
     */
    public function beforeApprove(Model $subject, ApprovalRequest $request, bool $final): void
    {
        if ($subject->status !== OfferStatus::Pending) {
            HrException::fail('hr::approvals.not_pending');
        }
    }

    /**
     * @param  Offer  $subject
     */
    public function onApproved(Model $subject, ApprovalRequest $request): void
    {
        $subject->update(['status' => OfferStatus::Approved]);
    }

    /**
     * @param  Offer  $subject
     */
    public function onRejected(Model $subject, ApprovalRequest $request): void
    {
        $subject->update(['status' => OfferStatus::Rejected]);
    }

    /**
     * @param  Offer  $subject
     */
    public function summary(Model $subject): array
    {
        $application = $subject->application()->with(['candidate', 'opening'])->first();

        return [
            'offer_id' => $subject->id,
            'candidate' => $application?->candidate?->name,
            'opening' => $application?->opening?->title,
            'start_on' => $subject->start_on->toDateString(),
            'expires_on' => $subject->expires_on?->toDateString(),
            'compensation' => $subject->compensation,
            'terms' => $subject->terms,
        ];
    }
}
