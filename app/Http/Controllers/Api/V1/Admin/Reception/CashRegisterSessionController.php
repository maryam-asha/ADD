<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Domain\Finance\Models\CashRegisterSession;
use App\Domain\Finance\Services\CashRegisterService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\CloseCashRegisterSessionRequest;
use App\Http\Requests\Admin\Reception\OpenCashRegisterSessionRequest;
use App\Http\Resources\CashRegisterSessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CashRegisterSessionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CashRegisterSession::with(['user', 'branch'])->latest('opened_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        $perPage = $request->integer('per_page', 15);

        return CashRegisterSessionResource::collection($query->paginate($perPage));
    }

    public function current(Request $request, CashRegisterService $service): JsonResponse
    {
        $session = $service->currentSession($request->user());

        if (! $session) {
            return response()->json(['data' => null]);
        }

        return (new CashRegisterSessionResource($session))->response();
    }

    public function open(OpenCashRegisterSessionRequest $request, CashRegisterService $service): JsonResponse
    {
        $session = $service->openSession(
            $request->user(),
            (int) $request->validated('branch_id'),
            (string) ($request->validated('opening_balance') ?? '0.00'),
            $request->validated('notes')
        );

        return (new CashRegisterSessionResource($session))
            ->response()
            ->setStatusCode(201);
    }

    public function close(
        CloseCashRegisterSessionRequest $request,
        CashRegisterSession $cashRegisterSession,
        CashRegisterService $service
    ): JsonResponse {
        $session = $service->closeSession(
            $cashRegisterSession,
            (string) $request->validated('closing_balance'),
            $request->validated('notes')
        );

        return (new CashRegisterSessionResource($session))->response();
    }
}
