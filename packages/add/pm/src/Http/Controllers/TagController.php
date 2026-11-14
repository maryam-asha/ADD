<?php

namespace Add\Pm\Http\Controllers;

use Add\Pm\Http\Requests\SaveTagRequest;
use Add\Pm\Http\Resources\TagResource;
use Add\Pm\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The shared set of labels. Anyone signed in may read them (cards show
 * them); changing the set is the manage ability's, judged on the routes.
 */
class TagController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => TagResource::collection(Tag::query()->orderBy('name')->get())]);
    }

    public function store(SaveTagRequest $request): JsonResponse
    {
        $tag = Tag::query()->create($request->validated());

        return response()->json(['data' => new TagResource($tag)], 201);
    }

    public function update(SaveTagRequest $request, Tag $tag): JsonResponse
    {
        $tag->update($request->validated());

        return response()->json(['data' => new TagResource($tag)]);
    }

    public function destroy(Tag $tag): JsonResponse
    {
        $tag->delete();

        return response()->json(['message' => __('pm::pm.tag_deleted')]);
    }
}
