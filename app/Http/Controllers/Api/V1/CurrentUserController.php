<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserController extends Controller
{
    /**
     * Shared by both the member app and the ops dashboard — same User model,
     * same shape, regardless of whether the request authenticated via an API
     * token (Sanctum) or a first-party SPA session (Fortify + Sanctum SPA).
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }
}
