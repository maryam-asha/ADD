<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Ecosystem\Models\PrivacyPolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PrivacyPolicyController extends Controller
{
    public function show(): JsonResponse
    {
        $policy = PrivacyPolicy::first();

        // One language, the request's (or the fallback), as Markdown ready to
        // render — nothing else; null until the operations team has written one.
        return response()->json(['data' => ['content' => $policy?->translate('content')]]);
    }

    public function consent(): JsonResponse
    {
        $policy = PrivacyPolicy::first();

        auth()->user()->privacyPolicyConsents()->create([
            'privacy_policy_id' => $policy->id,
            'agreed_at' => now(),
        ]);

        return response()->json(['message' => __('api.system.consent_recorded')]);
    }
}
