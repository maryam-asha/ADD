<?php

namespace Add\Hr\Core\Http\Controllers\Admin;

use Add\Hr\Core\Data\Alert;
use Add\Hr\Core\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class AlertController extends Controller
{
    public function index(AlertService $alerts): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (Alert $alert) => $alert->toArray(), $alerts->current()),
        ]);
    }
}
