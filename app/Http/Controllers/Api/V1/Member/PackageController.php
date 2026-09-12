<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Membership\Services\PackageBrowseService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    public function index(Request $request, PackageBrowseService $service): JsonResponse
    {
        $data = $service->getPackages($request->user(), $request->input('module'));

        return response()->json([
            'data' => $data,
        ]);
    }
}
