<?php

namespace Add\Hr\Core\Services;

use Add\Hr\Core\Models\DocumentType;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\EmployeeDocument;
use Add\Hr\Support\LocalDay;

/**
 * Whether an employee's file is complete: every required document type for
 * their contract type is on file, and none of their current documents has
 * expired. A type's current document is its latest upload.
 */
class DocumentCompliance
{
    /**
     * @return array{missing: list<DocumentType>, expired: list<EmployeeDocument>, complete: bool}
     */
    public function for(Employee $employee): array
    {
        $today = LocalDay::today()->toDateString();
        $contractType = ($employee->currentContract() ?? $employee->contracts()->latest('starts_on')->first())?->type->value;

        $current = $employee->documents()
            ->with('documentType')
            ->orderBy('id')
            ->get()
            ->keyBy('document_type_id');

        $missing = DocumentType::query()
            ->where('is_required', true)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (DocumentType $type) => $type->appliesTo($contractType) && ! $current->has($type->id))
            ->values()
            ->all();

        $expired = $current
            ->filter(fn (EmployeeDocument $doc) => $doc->expires_on !== null && $doc->expires_on->toDateString() < $today)
            ->values()
            ->all();

        return [
            'missing' => $missing,
            'expired' => $expired,
            'complete' => $missing === [] && $expired === [],
        ];
    }
}
