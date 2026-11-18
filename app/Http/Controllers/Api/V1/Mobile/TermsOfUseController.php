<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Domain\Ecosystem\Models\TermsOfUse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The terms of use, readable before login — sign-up shows them before there
 * is an account.
 */
class TermsOfUseController extends Controller
{
    public function show(): JsonResponse
    {
        // One language, the request's (or the fallback), as Markdown ready to
        // render — nothing else; null until the operations team has written them.
        return response()->json(['data' => ['content' => TermsOfUse::first()?->translate('content')]]);
    }
}
