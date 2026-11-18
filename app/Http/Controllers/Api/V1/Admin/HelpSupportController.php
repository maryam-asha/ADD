<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ecosystem\Models\HelpSupport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateHelpSupportRequest;
use Illuminate\Http\JsonResponse;

class HelpSupportController extends Controller
{
    public function show(): JsonResponse
    {
        $page = HelpSupport::first();

        // Both languages: this is what the dashboard's editor edits.
        return response()->json(['data' => $page === null ? null : [
            'id' => $page->id,
            'content' => $page->content,
            'updated_at' => $page->updated_at?->toIso8601String(),
        ]]);
    }

    public function update(UpdateHelpSupportRequest $request): JsonResponse
    {
        // The first save creates the one row; every later one edits it.
        $page = HelpSupport::first() ?? new HelpSupport;
        $page->fill($request->validated())->save();

        return response()->json(['message' => __('api.system.updated')]);
    }
}
