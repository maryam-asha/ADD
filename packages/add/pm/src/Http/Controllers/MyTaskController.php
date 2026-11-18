<?php

namespace Add\Pm\Http\Controllers;

use Add\Pm\Enums\TaskStatus;
use Add\Pm\Http\Resources\TaskResource;
use Add\Pm\Services\TaskQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * "My tasks": the person's own view across every live project they are on,
 * and the four numbers shown above it. It only ever reads what belongs to
 * the person asking, so it needs no check beyond being signed in.
 */
class MyTaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([...TaskQuery::rules(), 'scope' => ['sometimes', Rule::in(TaskQuery::SCOPES)]]);

        $query = TaskQuery::filter(TaskQuery::mine($this->userId($request), $request->input('scope', 'assigned')), $request);

        return response()->json(['data' => TaskResource::forMany(TaskQuery::dueOrder($query)->get())]);
    }

    /** What is late, due today, due this week and already done, among what is assigned to the person. */
    public function counts(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        $mine = fn () => TaskQuery::mine($userId, 'assigned');

        return response()->json(['data' => [
            'overdue' => TaskQuery::due($mine(), 'overdue')->count(),
            'today' => TaskQuery::due($mine(), 'today')->count(),
            'week' => TaskQuery::due($mine(), 'week')->count(),
            'completed' => $mine()->where('status', TaskStatus::Done->value)->count(),
        ]]);
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }
}
