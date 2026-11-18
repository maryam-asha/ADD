<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ecosystem\Models\TermsOfUse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateTermsOfUseRequest;
use Illuminate\Http\JsonResponse;

class TermsOfUseController extends Controller
{
    public function show(): JsonResponse
    {
        $terms = TermsOfUse::first();

        // Both languages: this is what the dashboard's editor edits.
        return response()->json(['data' => $terms === null ? null : [
            'id' => $terms->id,
            'content' => $terms->content,
            'updated_at' => $terms->updated_at?->toIso8601String(),
        ]]);
    }

    public function update(UpdateTermsOfUseRequest $request): JsonResponse
    {
        // The first save creates the one row; every later one edits it.
        $terms = TermsOfUse::first() ?? new TermsOfUse;
        $terms->fill($request->validated())->save();

        return response()->json(['message' => __('api.system.updated')]);
    }
}
