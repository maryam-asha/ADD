<?php

namespace App\Domain\Services\Services;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Services\Enums\FieldType;
use App\Domain\Services\Enums\LocationSource;
use App\Domain\Services\Enums\RequestStatus;
use App\Domain\Services\Models\ServiceRequest;
use App\Domain\Services\Models\ServiceRequestValue;
use App\Domain\Services\Models\ServiceType;
use App\Domain\Services\Models\ServiceTypeField;
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
     * Build a Laravel validation rule array from the service type's active fields.
     * Called by StoreServiceRequestRequest before this service runs, so validation
     * happens at the Form Request layer as expected.
     *
     * @return array<string, mixed>  keyed by "fields.<key>"
     */
    public function buildValidationRules(ServiceType $type): array
    {
        $rules = [];

        foreach ($type->fields->where('is_active', true) as $field) {
            $key = "fields.{$field->key}";

            $fieldRules = $this->fieldRules($field);

            if (! empty($fieldRules)) {
                $rules[$key] = $fieldRules;
            }
        }

        return $rules;
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
                'user_id'         => $member->id,
                'branch_id'       => $branchId,
                'space_id'        => $spaceId,
                'location_source' => $locationSource,
                'status'          => RequestStatus::Pending,
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
            'status'          => $newStatus,
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
        $fieldsByKey = $type->fields->where('is_active', true)->keyBy('key');

        foreach ($fieldValues as $key => $value) {
            /** @var ServiceTypeField|null $field */
            $field = $fieldsByKey->get($key);

            if ($field === null || $value === null) {
                continue;
            }

            // File fields: store after moving to permanent location
            if ($field->type === FieldType::File && $value instanceof UploadedFile) {
                $path  = $value->store("service-requests/{$request->id}", 'public');
                $value = $path;
            }

            ServiceRequestValue::fromFieldValue($request->id, $field, $value)->save();
        }
    }

    /**
     * Translate a field's `validation` JSON into a Laravel rule array.
     * The `validation` column stores compact rule fragments, e.g.:
     *   {"max": 500}  →  ["nullable","string","max:500"]
     *   {"required": true, "accept": "image/*"}  →  ["required","file","mimes:jpg,png,...]
     */
    private function fieldRules(ServiceTypeField $field): array
    {
        $rules = [];
        $v     = $field->validation ?? [];

        $required = ($v['required'] ?? false) || $field->is_required;
        $rules[]  = $required ? 'required' : 'nullable';

        switch ($field->type) {
            case FieldType::Text:
            case FieldType::Textarea:
                $rules[] = 'string';
                if (isset($v['max'])) {
                    $rules[] = "max:{$v['max']}";
                }
                if (isset($v['min'])) {
                    $rules[] = "min:{$v['min']}";
                }
                if (isset($v['regex'])) {
                    $rules[] = "regex:{$v['regex']}";
                }
                break;

            case FieldType::Number:
                $rules[] = 'numeric';
                if (isset($v['min'])) {
                    $rules[] = "min:{$v['min']}";
                }
                if (isset($v['max'])) {
                    $rules[] = "max:{$v['max']}";
                }
                break;

            case FieldType::Date:
                $rules[] = 'date';
                break;

            case FieldType::Select:
                $options = collect($field->options ?? [])->pluck('value')->implode(',');
                if ($options) {
                    $rules[] = "in:{$options}";
                }
                break;

            case FieldType::Checkbox:
                $rules[] = 'array';
                if ($field->options) {
                    $options = collect($field->options)->pluck('value')->implode(',');
                    $rules[] = "in:{$options}";
                }
                break;

            case FieldType::Rating:
                $rules[] = 'integer';
                $rules[] = 'min:' . ($v['min'] ?? 1);
                $rules[] = 'max:' . ($v['max'] ?? 5);
                break;

            case FieldType::File:
                $rules[] = 'file';
                if (isset($v['accept'])) {
                    // accept:"image/*" → mimes:jpg,png,gif,webp,svg
                    $rules[] = $this->acceptToMimes($v['accept']);
                }
                if (isset($v['max_kb'])) {
                    $rules[] = "max:{$v['max_kb']}";
                }
                break;
        }

        return $rules;
    }

    private function acceptToMimes(string $accept): string
    {
        $map = [
            'image/*'  => 'mimes:jpg,jpeg,png,gif,webp,svg',
            'video/*'  => 'mimes:mp4,mov,avi,mkv',
            '.pdf'     => 'mimes:pdf',
            '.doc'     => 'mimes:doc,docx',
        ];

        return $map[$accept] ?? "mimes:{$accept}";
    }
}
