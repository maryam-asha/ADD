<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Membership\Models\PlanModule;
use App\Http\Requests\Admin\StorePlanModuleRequest;
use App\Http\Requests\Admin\UpdatePlanModuleRequest;
use App\Http\Resources\PlanModuleResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class PlanModuleController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return PlanModule::class;
    }

    protected function resourceClass(): string
    {
        return PlanModuleResource::class;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->withCount('plans');
    }

    public function show(int $id): JsonResource
    {
        $module = PlanModule::withCount('plans')->with('plans')->findOrFail($id);

        return new PlanModuleResource($module);
    }

    public function store(StorePlanModuleRequest $request): PlanModuleResource
    {
        $data = $request->validated();

        if (empty($data['slug'])) {
            $nameForSlug = $data['name']['en'] ?? ($data['name']['ar'] ?? 'module');
            $baseSlug = Str::slug($nameForSlug);
            if (empty($baseSlug)) {
                $baseSlug = 'module';
            }
            $slug = $baseSlug;
            $counter = 1;
            while (PlanModule::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $data['slug'] = $slug;
        }

        $module = PlanModule::create(array_merge(
            ['order' => 0, 'is_active' => true],
            $data
        ));

        return new PlanModuleResource($module);
    }

    public function update(UpdatePlanModuleRequest $request, PlanModule $planModule): JsonResponse
    {
        $data = $request->validated();

        if (empty($data['slug'])) {
            unset($data['slug']);
        }

        $planModule->update($data);

        return response()->json(['message' => __('api.admin.plan_module_updated')]);
    }
}
