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

        // Both languages: this is what the dashboard's editor edits.
        return response()->json(['data' => $policy === null ? null : [
            'id' => $policy->id,
            'content' => $policy->content,
            'updated_at' => $policy->updated_at?->toIso8601String(),
        ]]);
    }

    public function update(UpdatePrivacyPolicyRequest $request): JsonResponse
    {
        // fill()->save(), not update(): update() on a model that was never
        // saved does nothing and returns false, so with no policy row yet
        // the first edit answered 200 and stored nothing.
        $policy = PrivacyPolicy::first() ?? new PrivacyPolicy;
        $policy->fill($request->validated())->save();

        return response()->json(['message' => __('api.system.updated')]);
    }
}
