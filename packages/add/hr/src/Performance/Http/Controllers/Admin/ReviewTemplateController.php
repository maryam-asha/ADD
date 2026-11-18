<?php

namespace Add\Hr\Performance\Http\Controllers\Admin;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Performance\Http\Requests\ReviewTemplateRequest;
use Add\Hr\Performance\Http\Resources\ReviewTemplateResource;
use Add\Hr\Performance\Models\ReviewTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class ReviewTemplateController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ReviewTemplateResource::collection(ReviewTemplate::query()->orderBy('id')->get());
    }

    public function show(ReviewTemplate $template): ReviewTemplateResource
    {
        return new ReviewTemplateResource($template);
    }

    public function store(ReviewTemplateRequest $request): ReviewTemplateResource
    {
        $template = ReviewTemplate::query()->create($request->validated());
        HrAudit::record('review_template_created', $template);

        return new ReviewTemplateResource($template);
    }

    public function update(ReviewTemplateRequest $request, ReviewTemplate $template): JsonResponse
    {
        $template->update($request->validated());
        HrAudit::record('review_template_updated', $template, ['changes' => array_keys($template->getChanges())]);

        return response()->json(['message' => __('hr::performance.template_updated')]);
    }
}
