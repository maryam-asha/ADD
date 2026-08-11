<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Domain\Identity\Models\ErrorLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\StoreErrorLogRequest;
use App\Http\Resources\ErrorLogResource;

class ErrorLogController extends Controller
{
    public function store(StoreErrorLogRequest $request): ErrorLogResource
    {
        $data = $request->validated();

        return new ErrorLogResource(ErrorLog::create([
            ...$data,
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]));
    }
}
