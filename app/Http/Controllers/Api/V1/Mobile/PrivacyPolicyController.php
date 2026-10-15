<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Domain\Ecosystem\Models\PrivacyPolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The privacy policy, readable before login — sign-up shows it before there
 * is an account. Recording a member's consent stays behind login
 * (Member\PrivacyPolicyController::consent()).
 */
class PrivacyPolicyController extends Controller
{
    public function show(): JsonResponse
    {
        // One language, the request's (or the fallback), as Markdown ready to
        // render — nothing else; null until the operations team has written one.
        return response()->json(['data' => ['content' => PrivacyPolicy::first()?->translate('content')]]);
    }
}
