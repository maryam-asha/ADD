<?php

namespace Add\Hr\Core\Http\Controllers\Admin;

use Add\Hr\Core\Http\Requests\StorePositionRequest;
use Add\Hr\Core\Http\Requests\UpdatePositionRequest;
use Add\Hr\Core\Http\Resources\PositionResource;
use Add\Hr\Core\Models\Position;
use Add\Hr\Support\HrException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class PositionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $positions = Position::query()
            ->withCount(['assignments as occupied' => fn ($q) => $q->openFrom(CarbonImmutable::today())])
            ->when($request->integer('org_unit_id'), fn ($q, $unitId) => $q->where('org_unit_id', $unitId))
            ->orderBy('id')
            ->get();

        return PositionResource::collection($positions);
    }

    public function store(StorePositionRequest $request): PositionResource
    {
        return new PositionResource(Position::query()->create($request->validated()));
    }

    public function show(Position $position): PositionResource
    {
        return new PositionResource($position);
    }

    /**
     * A held position keeps its unit — its holders' assignments name that
     * unit — and never shrinks below the seats already taken.
     */
    public function update(UpdatePositionRequest $request, Position $position): JsonResponse
    {
        $data = $request->validated();
        $held = $position->occupiedCount();

        if (isset($data['org_unit_id']) && (int) $data['org_unit_id'] !== (int) $position->org_unit_id && $held > 0) {
            HrException::fail('hr::core.position_held');
        }

        if (isset($data['headcount']) && $data['headcount'] < $held) {
            HrException::fail('hr::core.headcount_below_occupancy', ['count' => $held]);
        }

        $position->update($data);

        return response()->json(['message' => __('hr::core.position_updated')]);
    }

    /**
     * A position anyone has ever held stays, as part of their job history.
     */
    public function destroy(Position $position): Response
    {
        if ($position->assignments()->exists()) {
            HrException::fail('hr::core.position_in_use', [], 409);
        }

        $position->delete();

        return response()->noContent();
    }
}
