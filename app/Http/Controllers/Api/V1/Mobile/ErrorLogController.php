<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Domain\Identity\Models\ErrorLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\StoreErrorLogRequest;
use Illuminate\Http\JsonResponse;

class ErrorLogController extends Controller
{
    public function store(StoreErrorLogRequest $request): JsonResponse
    {
        $data = $request->validated();

        ErrorLog::create([
            ...$data,
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]);

        return response()->json(['message' => __('api.mobile.error_logged')], 201);
    }
}
