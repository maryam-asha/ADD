<?php

namespace App\Http\Requests\Services;

use App\Domain\Services\Enums\LocationSource;
use App\Domain\Services\Services\ServiceRequestService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a member's service request submission.
 *
 * Field-level rules (fields.*) are built dynamically at runtime from the
 * service type's active fields, so no rule changes are needed in PHP when
 * a field is added or modified in the dashboard.
 */
class StoreServiceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth:sanctum + role:member already guard the route
    }

    public function rules(): array
    {
        $baseRules = [
            'branch_id'       => ['nullable', 'integer', 'exists:branches,id'],
            'space_id'        => ['nullable', 'integer', 'exists:spaces,id'],
            'location_source' => ['nullable', 'string', 'in:qr,manual,none'],
            'fields'          => ['nullable', 'array'],
        ];

        // Dynamic field rules — resolved from the service type if we can resolve it here.
        // The controller re-validates via ServiceRequestService::buildValidationRules()
        // after resolving the service type, so these are the structural wrappers only.
        return $baseRules;
    }

    /**
     * Resolve the LocationSource enum from the request input.
     */
    public function locationSource(): LocationSource
    {
        return LocationSource::tryFrom($this->input('location_source', 'none'))
            ?? LocationSource::None;
    }
}
