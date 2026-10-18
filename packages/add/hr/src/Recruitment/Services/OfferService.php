<?php

namespace Add\Hr\Recruitment\Services;

use Add\Hr\Approvals\Services\ApprovalEngine;
use Add\Hr\Audit\HrAudit;
use Add\Hr\Contracts\HrClock;
use Add\Hr\Recruitment\Enums\OfferStatus;
use Add\Hr\Recruitment\Enums\RequisitionStatus;
use Add\Hr\Recruitment\Enums\StageType;
use Add\Hr\Recruitment\Models\Application;
use Add\Hr\Recruitment\Models\Offer;
use Add\Hr\Recruitment\Models\PipelineStage;
use Add\Hr\Support\HrException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Offers: approved through approvals (the requisition's hiring manager is
 * the requester), then made, and accepted or declined. One offer is in
 * play per application at a time.
 */
class OfferService
{
    public const APPROVAL_TYPE = 'offer';

    public function __construct(
        private readonly ApprovalEngine $approvals,
        private readonly StageRecorder $stages,
        private readonly HrClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function make(Application $application, array $data, int|string|null $actorId): Offer
    {
        return DB::transaction(function () use ($application, $data, $actorId) {
            $application = Application::query()->with('opening.requisition.hiringManager')->lockForUpdate()->findOrFail($application->id);
            $type = PipelineStage::typeOf($application->stage);

            if (! in_array($type, [StageType::Open, StageType::Offer], true) || $application->liveOffer()->exists()) {
                HrException::fail('hr::recruitment.offer_not_allowed');
            }

            $requisition = $application->opening->requisition;

            if ($requisition->status !== RequisitionStatus::Approved) {
                HrException::fail('hr::recruitment.requisition_not_open');
            }

            $offer = $application->offers()->create([
                'start_on' => $data['start_on'],
                'expires_on' => $data['expires_on'] ?? null,
                'compensation' => $data['compensation'] ?? null,
                'terms' => $data['terms'] ?? null,
                'status' => OfferStatus::Pending,
                'created_by' => $actorId,
            ]);

            $offer->approval_request_id = $this->approvals->submit($offer, self::APPROVAL_TYPE, $requisition->hiringManager, $actorId)->id;
            $offer->save();

            if ($type !== StageType::Offer) {
                $this->stages->move($application, PipelineStage::codeOf(StageType::Offer), null, $actorId);
            }

            HrAudit::record('offer_made', $offer);

            return $offer;
        });
    }

    public function accept(Offer $offer): Offer
    {
        return $this->respond($offer, OfferStatus::Accepted, null);
    }

    public function decline(Offer $offer, ?string $note): Offer
    {
        return $this->respond($offer, OfferStatus::Declined, $note);
    }

    public function withdraw(Offer $offer): Offer
    {
        return DB::transaction(function () use ($offer) {
            $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);

            if (! in_array($offer->status, [OfferStatus::Pending, OfferStatus::Approved, OfferStatus::Accepted], true)) {
                HrException::fail('hr::recruitment.offer_not_live');
            }

            $this->end($offer);

            return $offer;
        });
    }

    /**
     * Calls off the application's offer in play, if any (the application is
     * being rejected or withdrawn). Inside the caller's transaction.
     */
    public function withdrawLive(Application $application): void
    {
        $offer = $application->liveOffer()->lockForUpdate()->first();

        if ($offer !== null) {
            $this->end($offer);
        }
    }

    private function end(Offer $offer): void
    {
        if ($offer->status === OfferStatus::Pending && $offer->approvalRequest?->isPending()) {
            $this->approvals->cancel($offer->approvalRequest);
        }

        $offer->update(['status' => OfferStatus::Withdrawn]);
        HrAudit::record('offer_withdrawn', $offer);
    }

    private function respond(Offer $offer, OfferStatus $status, ?string $note): Offer
    {
        return DB::transaction(function () use ($offer, $status, $note) {
            $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);

            if ($offer->status !== OfferStatus::Approved) {
                HrException::fail('hr::recruitment.offer_not_approved');
            }

            if ($status === OfferStatus::Accepted && $offer->expires_on !== null
                && $offer->expires_on->toDateString() < CarbonImmutable::now($this->clock->timezone())->toDateString()) {
                HrException::fail('hr::recruitment.offer_expired');
            }

            $offer->update(['status' => $status, 'responded_at' => now(), 'response_note' => $note]);
            HrAudit::record('offer_'.$status->value, $offer);

            return $offer;
        });
    }
}
