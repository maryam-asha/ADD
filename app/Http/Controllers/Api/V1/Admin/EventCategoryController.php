<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Experience\Models\EventCategory;
use App\Http\Requests\Admin\StoreEventCategoryRequest;
use App\Http\Requests\Admin\UpdateEventCategoryRequest;
use App\Http\Resources\EventCategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class EventCategoryController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return EventCategory::class;
    }

    protected function resourceClass(): string
    {
        return EventCategoryResource::class;
    }

    public function store(StoreEventCategoryRequest $request): EventCategoryResource
    {
        $data = $request->validated();

        if (empty($data['slug'])) {
            $nameForSlug = $data['name']['en'] ?? ($data['name']['ar'] ?? 'category');
            $baseSlug = Str::slug($nameForSlug);
            if (empty($baseSlug)) {
                $baseSlug = 'category';
            }
            $slug = $baseSlug;
            $counter = 1;
            while (EventCategory::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $data['slug'] = $slug;
        }

        $category = EventCategory::create(array_merge(
            ['order' => 0, 'is_active' => true],
            $data
        ));

        return new EventCategoryResource($category);
    }

    public function update(UpdateEventCategoryRequest $request, EventCategory $eventCategory): JsonResponse
    {
        $data = $request->validated();

        if (empty($data['slug'])) {
            unset($data['slug']);
        }

        $eventCategory->update($data);

        return response()->json(['message' => __('api.admin.event_category_updated')]);
    }
}
