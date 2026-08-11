<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\ErrorLog;
use App\Http\Controllers\Controller;
use App\Http\Resources\ErrorLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Deliberately not extending AdminResourceController: that base class's
 * index() returns every row unpaginated, which fits small bounded tables
 * (Founders, Partners) but not a table that can grow quickly from
 * client-reported crashes — same reasoning UserController already uses for
 * not extending it.
 */
class ErrorLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ErrorLog::query()->latest();

        if ($platform = $request->query('platform')) {
            $query->where('platform', $platform);
        }

        if ($errorType = $request->query('error_type')) {
            $query->where('error_type', $errorType);
        }

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('occurred_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('occurred_at', '<=', $to);
        }

        return ErrorLogResource::collection($query->paginate(25));
    }

    public function show(ErrorLog $errorLog): ErrorLogResource
    {
        return new ErrorLogResource($errorLog);
    }

    public function destroy(ErrorLog $errorLog): Response
    {
        $errorLog->delete();

        return response()->noContent();
    }
}
