<?php

namespace App\Domain\Services\Services;

use App\Domain\Forms\Services\FormSubmissionService;
use App\Domain\Identity\Models\User;
use App\Domain\Services\Enums\LocationSource;
use App\Domain\Services\Enums\RequestStatus;
use App\Domain\Services\Models\ServiceRequest;
use App\Domain\Services\Models\ServiceType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and updates ServiceRequest records for form-based service types.
 *
 * The answers themselves go through the shared form engine
 * (FormSubmissionService), against the type's request form — so adding or
 * changing form fields requires no code change, only a DB edit.
 */
class ServiceRequestService
{
    public function __construct(
        private readonly FormSubmissionService $forms,
    ) {}

    /**
     * Persist a new service request and its answers inside a transaction.
     *
     * @param  array<string, mixed>  $fieldValues  Validated answers keyed by field key
     */
    public function submit(
        ServiceType $type,
        User $member,
        array $fieldValues,
        ?int $branchId = null,
        ?int $spaceId = null,
        LocationSource $locationSource = LocationSource::None,
    ): ServiceRequest {
        return DB::transaction(function () use ($type, $member, $fieldValues, $branchId, $spaceId, $locationSource) {
            $request = ServiceRequest::create([
                'service_type_id' => $type->id,
                'user_id' => $member->id,
                'branch_id' => $branchId,
                'space_id' => $spaceId,
                'location_source' => $locationSource,
                'status' => RequestStatus::Pending,
            ]);

            if ($form = $type->requestForm) {
                $this->forms->record($form, $request, $fieldValues, $member);
            }

            return $request->load('serviceType', 'formAnswers.field', 'branch', 'space');
        });
    }

    /**
     * Operations staff update: transition status and optionally add a
     * resolution note visible to the member.
     */
    public function updateStatus(
        ServiceRequest $serviceRequest,
        RequestStatus $newStatus,
        ?array $resolutionNote = null,
    ): ServiceRequest {
        if ($serviceRequest->isTerminal()) {
            throw ValidationException::withMessages([
                'status' => [__('api.services.request_already_terminal')],
            ]);
        }

        $serviceRequest->update([
            'status' => $newStatus,
            'resolution_note' => $resolutionNote ?? $serviceRequest->resolution_note,
            // Closing the request (resolved | rejected) is the moment it was
            // processed; in_progress is still being worked on.
            'processed_at' => $newStatus->isTerminal() ? now() : null,
        ]);

        return $serviceRequest->fresh('serviceType', 'user', 'branch', 'space', 'formAnswers.field');
    }
}
