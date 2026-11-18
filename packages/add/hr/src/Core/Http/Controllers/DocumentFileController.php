<?php

namespace Add\Hr\Core\Http\Controllers;

use Add\Hr\Core\Models\EmployeeDocument;
use Add\Hr\Core\Services\DocumentVault;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Serves one document behind a temporary signed URL (route middleware
 * `signed`). The signature is the whole credential: it was issued to an
 * authorized user minutes ago and names this document only.
 *
 * The id is resolved here, not by route model binding: binding runs before
 * the `signed` middleware, so an unsigned request would learn from 404 vs
 * 403 which document ids exist.
 */
class DocumentFileController extends Controller
{
    public function __invoke(Request $request, string $document, DocumentVault $vault): Response
    {
        return $vault->response(EmployeeDocument::query()->findOrFail($document), $request);
    }
}
