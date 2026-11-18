<?php

namespace Add\Hr\Recruitment\Http\Controllers;

use Add\Hr\Recruitment\Models\Application;
use Add\Hr\Recruitment\Services\CvVault;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Serves one CV behind a temporary signed URL, resolving the id only after
 * the signature passed (see DocumentFileController).
 */
class CvFileController extends Controller
{
    public function __invoke(Request $request, string $application, CvVault $vault): Response
    {
        $application = Application::query()->whereNotNull('cv_path')->findOrFail($application);

        return $vault->response($application, $request);
    }
}
