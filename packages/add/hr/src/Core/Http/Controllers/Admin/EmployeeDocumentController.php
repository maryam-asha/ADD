<?php

namespace Add\Hr\Core\Http\Controllers\Admin;

use Add\Hr\Core\Http\Requests\UploadDocumentRequest;
use Add\Hr\Core\Http\Resources\DocumentTypeResource;
use Add\Hr\Core\Http\Resources\EmployeeDocumentResource;
use Add\Hr\Core\Models\DocumentType;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\EmployeeDocument;
use Add\Hr\Core\Services\DocumentCompliance;
use Add\Hr\Core\Services\DocumentVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * An employee's documents for HR. There is deliberately no bulk download:
 * each file is opened one signed link at a time.
 */
class EmployeeDocumentController extends Controller
{
    public function __construct(private readonly DocumentVault $vault) {}

    public function index(Employee $employee, DocumentCompliance $compliance): AnonymousResourceCollection
    {
        $status = $compliance->for($employee);

        return EmployeeDocumentResource::collection(
            $employee->documents()->with('documentType')->latest('id')->get()
        )->additional(['compliance' => [
            'complete' => $status['complete'],
            'missing' => DocumentTypeResource::collection($status['missing']),
            'expired' => collect($status['expired'])->pluck('id')->values(),
        ]]);
    }

    public function store(UploadDocumentRequest $request, Employee $employee): EmployeeDocumentResource
    {
        $document = $this->vault->store(
            $employee,
            DocumentType::query()->findOrFail($request->validated('document_type_id')),
            $request->file('file'),
            $request->safe()->only(['issued_on', 'expires_on', 'notes']),
            $request->user()?->getAuthIdentifier(),
        );

        return new EmployeeDocumentResource($document->load('documentType'));
    }

    public function destroy(EmployeeDocument $document): Response
    {
        $document->delete();

        return response()->noContent();
    }

    public function link(Request $request, EmployeeDocument $document): JsonResponse
    {
        return response()->json(['data' => $this->vault->link($document, $request->user()?->getAuthIdentifier())]);
    }
}
