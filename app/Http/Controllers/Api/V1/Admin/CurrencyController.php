<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Finance\Models\Currency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCurrencyRequest;
use App\Http\Requests\Admin\UpdateCurrencyRequest;
use App\Http\Requests\Admin\UpdateCurrencyStatusRequest;
use App\Http\Resources\CurrencyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Deliberately not extending AdminResourceController: the primary key is a
 * client-supplied `code` string, not an autoincrement id with a generic
 * `order` shape, and "removing" a currency means deactivating it
 * (is_active = false), never a hard delete — plans/spaces/bookings/users
 * all carry a real FK to currencies.code with restrictOnDelete(), same
 * reasoning UserController uses for its own deviations from the generic
 * pattern.
 */
class CurrencyController extends Controller
{
    use LogsSensitiveActions;

    public function index(): AnonymousResourceCollection
    {
        return CurrencyResource::collection(
            Currency::query()->orderBy('order')->get()
        );
    }

    public function store(StoreCurrencyRequest $request): CurrencyResource
    {
        $currency = Currency::create(array_merge(
            ['is_active' => true, 'is_base' => false],
            $request->validated()
        ));

        $this->logSensitiveAction('currency_created', $currency, $request->validated());

        return new CurrencyResource($currency);
    }

    public function show(Currency $currency): CurrencyResource
    {
        return new CurrencyResource($currency);
    }

    public function update(UpdateCurrencyRequest $request, Currency $currency): JsonResponse
    {
        $currency->update($request->validated());

        return response()->json(['message' => __('api.admin.currency_updated')]);
    }

    /**
     * The base currency (is_base = true) can never be deactivated —
     * CurrencyConversionService/CurrencyResolver both depend on exactly
     * one always-active base row existing.
     */
    public function updateStatus(UpdateCurrencyStatusRequest $request, Currency $currency): JsonResponse
    {
        if ($currency->is_base) {
            return response()->json([
                'message' => __('api.currency.base_currency_status_locked'),
            ], 422);
        }

        $before = $currency->is_active;

        $currency->update(['is_active' => $request->validated('is_active')]);

        $this->logSensitiveAction('currency_status_changed', $currency, [
            'before' => $before,
            'after' => $currency->is_active,
        ]);

        return response()->json(['message' => __('api.admin.currency_status_updated')]);
    }
}
