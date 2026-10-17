<?php

namespace Add\Hr\Core\Http\Controllers\Admin;

use Add\Hr\Core\Http\Requests\StoreDocumentTypeRequest;
use Add\Hr\Core\Http\Requests\UpdateDocumentTypeRequest;
use Add\Hr\Core\Http\Resources\DocumentTypeResource;
use Add\Hr\Core\Models\DocumentType;
use Add\Hr\Support\HrException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class DocumentTypeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DocumentTypeResource::collection(DocumentType::query()->orderBy('id')->get());
    }

    public function store(StoreDocumentTypeRequest $request): DocumentTypeResource
    {
        return new DocumentTypeResource(DocumentType::query()->create($request->validated()));
    }

    public function show(DocumentType $documentType): DocumentTypeResource
    {
        return new DocumentTypeResource($documentType);
    }

    public function update(UpdateDocumentTypeRequest $request, DocumentType $documentType): JsonResponse
    {
        $documentType->update($request->validated());

        return response()->json(['message' => __('hr::core.document_type_updated')]);
    }

    /**
     * A type with documents on file stays; deactivate it instead.
     */
    public function destroy(DocumentType $documentType): Response
    {
        if ($documentType->documents()->withTrashed()->exists()) {
            HrException::fail('hr::core.document_type_in_use', [], 409);
        }

        $documentType->delete();

        return response()->noContent();
    }
}
