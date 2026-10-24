<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\Amenity;
use App\Http\Requests\Admin\StoreAmenityRequest;
use App\Http\Requests\Admin\UpdateAmenityRequest;
use App\Http\Resources\AmenityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * The amenity catalog. `key` is fixed once created — spaces and space types
 * store it — so update() never takes one; deleting an amenity turns it off
 * everywhere it was on (cascade).
 */
class AmenityController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Amenity::class;
    }

    protected function resourceClass(): string
    {
        return AmenityResource::class;
    }

    public function store(StoreAmenityRequest $request): AmenityResource
    {
        $data = $request->validated();
        $data['key'] ??= $this->uniqueKey($data['name']['en'] ?? $data['name']['ar']);

        return new AmenityResource(Amenity::query()->create(['order' => 0, 'is_active' => true, ...$data]));
    }

    public function update(UpdateAmenityRequest $request, Amenity $amenity): JsonResponse
    {
        $amenity->update($request->validated());

        return response()->json(['message' => __('api.admin.amenity_updated')]);
    }

    private function uniqueKey(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 32, '') ?: 'amenity';
        $key = $base;

        for ($i = 2; Amenity::query()->where('key', $key)->exists(); $i++) {
            $key = $base.'_'.$i;
        }

        return $key;
    }
}
