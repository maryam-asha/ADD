<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Ecosystem\Models\PrivacyPolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PrivacyPolicyController extends Controller
{
    /**
     * Reading the policy needs no login (Mobile\PrivacyPolicyController);
     * agreeing to it is the member's, so it stays here.
     */
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
