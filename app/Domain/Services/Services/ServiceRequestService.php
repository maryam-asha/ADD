<?php

namespace App\Domain\Services\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Services\Enums\LocationSource;
use App\Domain\Services\Enums\RequestStatus;
use App\Domain\Services\Models\ServiceRequest;
use App\Domain\Services\Models\ServiceRequestValue;
use App\Domain\Services\Models\ServiceType;
use App\Domain\Services\Models\ServiceTypeField;
use App\Support\Forms\FieldRuleBuilder;
use App\Support\Forms\FieldType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and updates ServiceRequest records for form-based service types.
 *
 * Field validation is built dynamically from `service_type_fields.validation`,
 * so adding or changing form fields requires no code change — only a DB edit.
 */
class ServiceRequestService
{
    /**
     * Laravel validation rules for the service type's active fields, keyed
     * by "fields.<key>". Built from the stored definitions by the shared
     * FieldRuleBuilder, so adding or changing a field stays a DB edit.
     *
     * Called by StoreServiceRequestRequest, which resolves the type from
     * the route slug before this service runs.
     *
     * @return array<string, mixed>
     */
    public function buildValidationRules(ServiceType $type): array
    {
        return app(FieldRuleBuilder::class)->rulesFor($type->activeFields);
    }

    /**
     * Persist a new service request and its field values inside a transaction.
     *
     * @param  array<string, mixed>  $fieldValues  Raw values keyed by field key
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

            $this->persistValues($request, $type, $fieldValues);

            return $request->load('serviceType', 'values.field', 'branch', 'space');
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
        ]);

        return $serviceRequest->fresh('serviceType', 'user', 'branch', 'space', 'values.field');
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    /**
     * Store each submitted field value into the correct typed column.
     */
    private function persistValues(ServiceRequest $request, ServiceType $type, array $fieldValues): void
    {
        $fieldsByKey = $type->activeFields->keyBy('key');

        foreach ($fieldValues as $key => $value) {
            /** @var ServiceTypeField|null $field */
            $field = $fieldsByKey->get($key);

            if ($field === null || $value === null) {
                continue;
            }

            // File fields: store after moving to permanent location
            if ($field->type === FieldType::File && $value instanceof UploadedFile) {
                $path = $value->store("service-requests/{$request->id}", 'public');
                $value = $path;
            }

            ServiceRequestValue::fromFieldValue($request->id, $field, $value)->save();
        }
    }
}
