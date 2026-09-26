<?php

namespace App\Http\Requests\Services;

use App\Domain\Forms\Models\Form;
use App\Domain\Services\Enums\LocationSource;
use App\Domain\Services\Models\ServiceType;
use App\Http\Requests\Concerns\ValidatesFormSubmission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a member's service request submission.
 *
 * Field-level rules (fields.*) are built at runtime from the service type's
 * request form — see ValidatesFormSubmission — so no rule changes are
 * needed in PHP when a field is added or modified in the dashboard.
 */
class StoreServiceRequestRequest extends FormRequest
{
    use ValidatesFormSubmission;

    public function authorize(): bool
    {
        return true; // auth:sanctum + role:member already guard the route
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'space_id' => ['nullable', 'integer', 'exists:spaces,id'],
            'location_source' => ['nullable', 'string', 'in:qr,manual,none'],
        ] + $this->formFieldRules();
    }

    /**
     * The request form of the service type this submission targets, from
     * the route id. If the type doesn't resolve, the payload is only
     * structurally checked and the controller returns its 404; visibility
     * and membership gating stay in the controller too.
     */
    protected function resolveSubmittedForm(): ?Form
    {
        return ServiceType::query()
            ->whereKey($this->route('id'))
            ->with('requestForm')
            ->first()
            ?->requestForm;
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
