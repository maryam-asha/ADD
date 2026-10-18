<?php

namespace Add\Hr\Core\Http\Controllers\Admin;

use Add\Hr\Core\Http\Requests\StoreChecklistTemplateRequest;
use Add\Hr\Core\Http\Requests\UpdateChecklistTemplateRequest;
use Add\Hr\Core\Http\Resources\ChecklistTemplateResource;
use Add\Hr\Core\Models\ChecklistTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class ChecklistTemplateController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ChecklistTemplateResource::collection(ChecklistTemplate::query()->orderBy('kind')->orderBy('id')->get());
    }

    public function store(StoreChecklistTemplateRequest $request): ChecklistTemplateResource
    {
        return new ChecklistTemplateResource(ChecklistTemplate::query()->create($request->validated()));
    }

    public function show(ChecklistTemplate $checklistTemplate): ChecklistTemplateResource
    {
        return new ChecklistTemplateResource($checklistTemplate);
    }

    public function update(UpdateChecklistTemplateRequest $request, ChecklistTemplate $checklistTemplate): JsonResponse
    {
        $checklistTemplate->update($request->validated());

        return response()->json(['message' => __('hr::core.checklist_template_updated')]);
    }

    /**
     * Tasks already given out are copies and stay.
     */
    public function destroy(ChecklistTemplate $checklistTemplate): Response
    {
        $checklistTemplate->delete();

        return response()->noContent();
    }
}
