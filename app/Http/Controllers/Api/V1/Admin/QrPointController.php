<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Kitchen\Enums\QrPointPlace;
use App\Domain\Kitchen\Models\QrPoint;
use App\Http\Requests\Admin\StoreQrPointRequest;
use App\Http\Resources\Kitchen\QrPointResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The QR a member scans at a desk or in a room to order from the kitchen.
 * Staff make one per place; making it again returns the one that exists.
 */
class QrPointController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return QrPoint::class;
    }

    protected function resourceClass(): string
    {
        return QrPointResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->orderBy('id');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        if ($request->filled('place_type')) {
            $query->where('place_type', $request->string('place_type')->toString());
        }
    }

    public function store(StoreQrPointRequest $request): JsonResponse
    {
        $type = QrPointPlace::from($request->validated('place_type'));
        $placeId = (int) $request->validated('place_id');

        $point = QrPoint::query()->firstOrCreate(
            ['place_type' => $type->value, 'place_id' => $placeId],
            ['branch_id' => $this->branchIdOf($type, $placeId), 'qr_value' => Str::random(40)],
        );

        if ($type === QrPointPlace::SeatDesk) {
            SeatDesk::query()->whereKey($placeId)->update(['qr_point_id' => $point->id]);
        }

        return (new QrPointResource($point))
            ->response()
            ->setStatusCode($point->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /** The desk loses its code with the point (the FK does the same on MySQL; SQLite cannot add one). */
    public function destroy(int $id): Response
    {
        $point = QrPoint::query()->findOrFail($id);

        DB::transaction(function () use ($point) {
            SeatDesk::query()->where('qr_point_id', $point->id)->update(['qr_point_id' => null]);
            $point->delete();
        });

        return response()->noContent();
    }

    private function branchIdOf(QrPointPlace $type, int $placeId): int
    {
        $space = match ($type) {
            QrPointPlace::SeatDesk => SeatDesk::query()->findOrFail($placeId)->space,
            QrPointPlace::Space => Space::query()->findOrFail($placeId),
        };

        return (int) $space->building->branch_id;
    }
}
