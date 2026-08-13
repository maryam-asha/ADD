<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ecosystem\Models\PrivacyPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdatePrivacyPolicyRequest;
use Illuminate\Http\JsonResponse;

class PrivacyPolicyController extends Controller
{
    public function show(): JsonResponse
    {
        $policy = PrivacyPolicy::first();

        return response()->json($policy);
    }

    public function update(UpdatePrivacyPolicyRequest $request): JsonResponse
    {
        $policy = PrivacyPolicy::first() ?? new PrivacyPolicy;
        $policy->update($request->validated());

        return response()->json(['message' => __('api.system.updated')]);
    }
}
