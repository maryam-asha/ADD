<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BroadcastNotificationRequest;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;

class AdminNotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function broadcast(BroadcastNotificationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $users = match ($validated['target']) {
            'all' => User::where('status', 'active')->get(),
            'role' => User::whereHas('roles', fn ($q) => $q->where('name', $validated['role_name']))
                ->where('status', 'active')
                ->get(),
            'users' => User::whereIn('id', $validated['user_ids'])
                ->where('status', 'active')
                ->get(),
        };

        foreach ($users as $user) {
            $locale = $user->preferred_language ?? config('app.locale');
            $title = $validated['title'][$locale] ?? $validated['title']['en'];
            $body = $validated['body'][$locale] ?? $validated['body']['en'];

            $this->notificationService->sendCustomToUser($user, $title, $body);
        }

        return response()->json(['message' => __('api.notification.broadcast_sent')]);
    }
}
