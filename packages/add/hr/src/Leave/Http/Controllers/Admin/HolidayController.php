<?php

namespace Add\Hr\Leave\Http\Controllers\Admin;

use Add\Hr\Leave\Http\Requests\StoreHolidayRequest;
use Add\Hr\Leave\Http\Requests\UpdateHolidayRequest;
use Add\Hr\Leave\Http\Resources\HolidayResource;
use Add\Hr\Leave\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Leaves already approved keep the days they were counted with.
 */
class HolidayController extends Controller
{
    /**
     * `?year=` keeps that year's holidays and every recurring one.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return HolidayResource::collection(
            Holiday::query()
                ->when($request->integer('year') ?: null, fn ($q, int $year) => $q->where(fn ($q) => $q
                    ->where('recurs_annually', true)
                    ->orWhere(fn ($q) => $q->where('starts_on', '<=', "{$year}-12-31")->where('ends_on', '>=', "{$year}-01-01"))))
                ->orderBy('starts_on')
                ->get()
        );
    }

    public function store(StoreHolidayRequest $request): HolidayResource
    {
        return new HolidayResource(Holiday::query()->create($request->validated())->refresh());
    }

    public function show(Holiday $holiday): HolidayResource
    {
        return new HolidayResource($holiday);
    }

    public function update(UpdateHolidayRequest $request, Holiday $holiday): JsonResponse
    {
        $holiday->update($request->validated());

        return response()->json(['message' => __('hr::leave.holiday_updated')]);
    }

    public function destroy(Holiday $holiday): Response
    {
        $holiday->delete();

        return response()->noContent();
    }
}
