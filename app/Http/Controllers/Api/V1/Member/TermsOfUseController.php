<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Ecosystem\Models\TermsOfUse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class TermsOfUseController extends Controller
{
    public function show(): JsonResponse
    {
        $terms = TermsOfUse::first();

        // One language, the request's (or the fallback), as Markdown ready to
        // render — nothing else; null until the operations team has written them.
        return response()->json(['data' => ['content' => $terms?->translate('content')]]);
    }
}
