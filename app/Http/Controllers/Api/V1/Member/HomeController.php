<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Http\Controllers\Controller;
use App\Services\Home\MemberHomeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function show(Request $request, MemberHomeService $home): JsonResponse
    {
        return response()->json(['data' => $home->for($request->user())]);
    }
}
