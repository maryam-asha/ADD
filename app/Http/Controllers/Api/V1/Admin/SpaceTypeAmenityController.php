<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\SpaceTypeAmenity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSpaceTypeAmenitiesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The type -> amenity config matrix: which AmenityType values are even
 * selectable for a given SpaceType (docs/decisions -- amenities move off
 * Resource entirely; StoreSpaceRequest cross-checks a space's submitted
 * amenities against this table). Deliberately not extending
 * AdminResourceController: this isn't paginated CRUD over rows with an id
 * — it's a fixed, always-4-row matrix keyed by SpaceType, same reasoning
 * UserController/ErrorLogController opt out for their own reasons.
 */
class SpaceTypeAmenityController extends Controller
{
    public function index(): JsonResponse
    {
        $configured = SpaceTypeAmenity::query()->get()->groupBy(
            fn (SpaceTypeAmenity $row) => $row->space_type->value
        );

        $data = collect(SpaceType::cases())->map(function (SpaceType $type) use ($configured) {
            return [
                'space_type' => $type->value,
                'amenities' => $configured->get($type->value, collect())
                    ->pluck('amenity')
                    ->values(),
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    public function update(UpdateSpaceTypeAmenitiesRequest $request, SpaceType $spaceType): JsonResponse
    {
        $amenities = $request->validated('amenities');

        DB::transaction(function () use ($spaceType, $amenities) {
            SpaceTypeAmenity::query()->where('space_type', $spaceType)->delete();

            foreach ($amenities as $amenity) {
                SpaceTypeAmenity::create([
                    'space_type' => $spaceType,
                    'amenity' => $amenity,
                ]);
            }
        });

        return response()->json(['message' => __('api.admin.space_type_amenities_updated')]);
    }
}
