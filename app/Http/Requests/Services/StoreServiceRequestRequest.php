<?php

namespace App\Http\Requests\Services;

use App\Domain\Services\Enums\LocationSource;
use App\Domain\Services\Models\ServiceType;
use App\Domain\Services\Services\ServiceRequestService;
use App\Support\Forms\FieldRuleBuilder;
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
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'space_id' => ['nullable', 'integer', 'exists:spaces,id'],
            'location_source' => ['nullable', 'string', 'in:qr,manual,none'],
            'fields' => ['nullable', 'array'],
        ];

        // Field rules come from the service type's stored definitions. The
        // id is on the route, so resolve it here rather than leaving the
        // payload unvalidated: if it doesn't resolve, fall through with the
        // structural rules only and let the controller return its 404.
        $type = $this->serviceType();

        if ($type === null) {
            return $baseRules;
        }

        return $baseRules + app(ServiceRequestService::class)->buildValidationRules($type);
    }

    /**
     * Name each generated rule path after the field the admin defined — see
     * FieldRuleBuilder::attributesFor().
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $type = $this->serviceType();

        return $type === null
            ? []
            : app(FieldRuleBuilder::class)->attributesFor($type->activeFields);
    }

    /**
     * The service type this submission targets, resolved from the route id.
     * Visibility and membership gating stay in the controller — this only
     * needs the field definitions to build rules from.
     */
    private function serviceType(): ?ServiceType
    {
        return ServiceType::query()
            ->whereKey($this->route('id'))
            ->with('activeFields')
            ->first();
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
