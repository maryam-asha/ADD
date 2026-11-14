<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Exceptions\KitchenOrderException;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Services\KitchenOrderService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectKitchenOrderRequest;
use App\Http\Resources\Kitchen\AdminKitchenOrderResource;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The kitchen's side of the orders (docs/superpowers/specs/2026-11-10-kitchen-orders-design.md):
 * the queue and its history, and accepting, rejecting, marking ready and
 * delivered. Not an AdminResourceController: orders grow without bound,
 * so the list paginates.
 */
class KitchenOrderController extends Controller
{
    private const RELATIONS = ['user', 'lines.item', 'seatDesk', 'space'];

    /**
     * GET admin/kitchen/orders — filters `branch_id`, `status` (comma
     * separated; the open ones — pending, preparing, ready — when absent),
     * `from` / `to` (days sent), `user_id`, `sort` (`oldest` — the
     * default, the live queue worked from its head — or `newest`, latest
     * submitted first, for the history).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', $this->statusList(...)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'in:oldest,newest'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $statuses = isset($validated['status'])
            ? array_map('trim', explode(',', $validated['status']))
            : KitchenOrderStatus::kitchenBoardValues();

        $orders = KitchenOrder::query()
            ->with(self::RELATIONS)
            ->whereIn('status', $statuses)
            // The request's branch (X-Branch-Id or branch_id, ResolveDefaultBranch), else the default one.
            ->where('branch_id', (int) ($validated['branch_id'] ?? Branch::defaultId()))
            ->when(isset($validated['user_id']), fn ($query) => $query->where('user_id', (int) $validated['user_id']))
            ->when(isset($validated['from']), fn ($query) => $query->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when(isset($validated['to']), fn ($query) => $query->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->when(
                ($validated['sort'] ?? 'oldest') === 'newest',
                fn ($query) => $query->latest()->latest('id'),
                fn ($query) => $query->oldest()->oldest('id'),
            )
            ->paginate((int) ($validated['per_page'] ?? 25));

        return AdminKitchenOrderResource::collection($orders);
    }

    /** GET admin/kitchen/orders/{order} */
    public function show(KitchenOrder $order): AdminKitchenOrderResource
    {
        return new AdminKitchenOrderResource($order->load(self::RELATIONS));
    }

    /** POST admin/kitchen/orders/{order}/accept — draws its lines from the kitchen store. */
    public function accept(Request $request, KitchenOrder $order, KitchenOrderService $orders): AdminKitchenOrderResource|JsonResponse
    {
        return $this->act(fn () => $orders->accept($order, $request->user()));
    }

    /** POST admin/kitchen/orders/{order}/reject `{reason}` — a pending order only. */
    public function reject(RejectKitchenOrderRequest $request, KitchenOrder $order, KitchenOrderService $orders): AdminKitchenOrderResource|JsonResponse
    {
        return $this->act(fn () => $orders->reject($order, $request->user(), $request->validated('reason')));
    }

    /** POST admin/kitchen/orders/{order}/ready — a preparing order only. */
    public function ready(Request $request, KitchenOrder $order, KitchenOrderService $orders): AdminKitchenOrderResource|JsonResponse
    {
        return $this->act(fn () => $orders->markReady($order, $request->user()));
    }

    /** POST admin/kitchen/orders/{order}/deliver — a preparing or ready order. */
    public function deliver(Request $request, KitchenOrder $order, KitchenOrderService $orders): AdminKitchenOrderResource|JsonResponse
    {
        return $this->act(fn () => $orders->markDelivered($order, $request->user()));
    }

    /** @param Closure(): KitchenOrder $action */
    private function act(Closure $action): AdminKitchenOrderResource|JsonResponse
    {
        try {
            $order = $action();
        } catch (KitchenOrderException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return new AdminKitchenOrderResource($order->load(self::RELATIONS));
    }

    /** Every comma-separated value of `status` is a status. */
    private function statusList(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (explode(',', (string) $value) as $status) {
            if (KitchenOrderStatus::tryFrom(trim($status)) === null) {
                $fail(__('validation.in', ['attribute' => $attribute]));

                return;
            }
        }
    }
}
