<?php

namespace Add\Hr\Core\Http\Controllers\Admin;

use Add\Hr\Core\Http\Requests\StoreOrgUnitRequest;
use Add\Hr\Core\Http\Requests\UpdateOrgUnitRequest;
use Add\Hr\Core\Http\Resources\OrgUnitResource;
use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Core\Services\OrgTree;
use Add\Hr\Support\HrException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class OrgUnitController extends Controller
{
    public function __construct(private readonly OrgTree $tree) {}

    /**
     * `?tree=1` returns root units with their `children` nested all the way
     * down; otherwise a flat list.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        if ($request->boolean('tree')) {
            return OrgUnitResource::collection($this->tree->nested());
        }

        return OrgUnitResource::collection(OrgUnit::query()->orderBy('id')->get());
    }

    public function store(StoreOrgUnitRequest $request): OrgUnitResource
    {
        return new OrgUnitResource(OrgUnit::query()->create($request->validated()));
    }

    public function show(OrgUnit $orgUnit): OrgUnitResource
    {
        return new OrgUnitResource($orgUnit->load('children'));
    }

    public function update(UpdateOrgUnitRequest $request, OrgUnit $orgUnit): JsonResponse
    {
        if ($request->has('parent_id')) {
            $this->tree->assertValidParent($orgUnit, $request->validated('parent_id'));
        }

        $orgUnit->update($request->validated());

        return response()->json(['message' => __('hr::core.org_unit_updated')]);
    }

    /**
     * Only an empty unit goes: no child units, no positions, nobody assigned.
     */
    public function destroy(OrgUnit $orgUnit): Response
    {
        if ($orgUnit->children()->exists() || $orgUnit->positions()->exists()) {
            HrException::fail('hr::core.org_unit_in_use', [], 409);
        }

        $orgUnit->delete();

        return response()->noContent();
    }
}
