<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Experience\Models\Event;
use App\Http\Requests\Admin\StoreEventRequest;
use App\Http\Requests\Admin\UpdateEventRequest;
use App\Http\Resources\EventResource;
use Illuminate\Http\JsonResponse;

class EventController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Event::class;
    }

    protected function resourceClass(): string
    {
        return EventResource::class;
    }

    public function store(StoreEventRequest $request): EventResource
    {
        return new EventResource(Event::create(array_merge(
            ['order' => 0, 'status' => 'draft'],
            $request->validated()
        )));
    }

    public function update(UpdateEventRequest $request, Event $event): JsonResponse
    {
        $event->update($request->validated());

        return response()->json(['message' => __('api.admin.event_updated')]);
    }
}
