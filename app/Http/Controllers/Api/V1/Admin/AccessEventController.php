<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessEventType;
use App\Domain\Access\Models\AccessEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccessEventResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The access log: every door opened, locked or refused, newest first.
 * Paginated and filtered like the error log — it grows with every scan.
 */
class AccessEventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'device_id' => ['nullable', 'integer'],
            'space_id' => ['nullable', 'integer'],
            'actor_user_id' => ['nullable', 'integer'],
            'event_type' => ['nullable', Rule::enum(AccessEventType::class)],
            'channel' => ['nullable', Rule::enum(AccessEventChannel::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AccessEvent::query()
            ->with(['device.space', 'actor', 'grant'])
            ->latest('occurred_at')->latest('id')
            ->when($filters['device_id'] ?? null, fn ($q, $id) => $q->where('device_id', $id))
            ->when($filters['space_id'] ?? null, fn ($q, $id) => $q->whereHas('device', fn ($d) => $d->where('space_id', $id)))
            ->when($filters['actor_user_id'] ?? null, fn ($q, $id) => $q->where('actor_user_id', $id))
            ->when($filters['event_type'] ?? null, fn ($q, $type) => $q->where('event_type', $type))
            ->when($filters['channel'] ?? null, fn ($q, $channel) => $q->where('channel', $channel))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('occurred_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('occurred_at', '<=', $to));

        return AccessEventResource::collection($query->paginate($filters['per_page'] ?? 25));
    }
}
