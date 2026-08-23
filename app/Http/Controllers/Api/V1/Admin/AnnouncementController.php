<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ecosystem\Models\Announcement;
use App\Http\Requests\Admin\StoreAnnouncementRequest;
use App\Http\Requests\Admin\UpdateAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Announcement::class;
    }

    protected function resourceClass(): string
    {
        return AnnouncementResource::class;
    }

    /**
     * `announcements` orders by `sort_order`, not the base class's hardcoded
     * `order` column — same precedent as `ContactLinkController`.
     */
    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->orderBy('sort_order');
    }

    /**
     * `sort_order`/`is_active` are set explicitly here, not left to the
     * migration's column defaults — same reasoning as
     * `ContactLinkController::store`/`FounderController::store`.
     */
    public function store(StoreAnnouncementRequest $request): AnnouncementResource
    {
        return new AnnouncementResource(Announcement::create(array_merge(
            ['sort_order' => 0, 'is_active' => true],
            $request->validated()
        )));
    }

    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        $announcement->update($request->validated());

        return response()->json(['message' => __('api.admin.announcement_updated')]);
    }
}
