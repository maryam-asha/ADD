<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * Just the assignable role names for now — no granular permissions yet,
     * so there's nothing per-role to expose beyond the name.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Role::query()->pluck('name'),
        ]);
    }
}
