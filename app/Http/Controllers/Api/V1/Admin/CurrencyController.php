<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCurrencyRequest;
use App\Http\Requests\Admin\UpdateCurrencyRequest;
use App\Http\Requests\Admin\UpdateCurrencyStatusRequest;
use App\Http\Resources\CurrencyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

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
     * Reassign which currency is the base. Blocked while any exchange_rates
     * rows exist — every stored rate_to_base becomes meaningless once the base
     * it was computed against changes (docs/decisions/multi-currency-support.md
     * §Addendum 2026-08-31).
     */
    public function updateBase(Currency $currency): JsonResponse
    {
        if ($currency->is_base) {
            return response()->json([
                'message' => __('api.currency.already_base'),
            ], 422);
        }

        if (! $currency->is_active) {
            return response()->json([
                'message' => __('api.currency.inactive_cannot_be_base'),
            ], 422);
        }

        if (ExchangeRate::query()->exists()) {
            return response()->json([
                'message' => __('api.currency.exchange_rates_block_reassignment'),
            ], 422);
        }

        $oldCode = Currency::where('is_base', true)->value('code');

        DB::transaction(function () use ($currency): void {
            Currency::where('is_base', true)->update(['is_base' => false]);
            $currency->update(['is_base' => true]);

            $baseCurrencyCount = Currency::where('is_base', true)->count();
            if ($baseCurrencyCount !== 1) {
                throw new \RuntimeException("Base currency invariant violated: expected exactly 1 base row, found {$baseCurrencyCount}.");
            }
        });

        $this->logSensitiveAction('currency_base_changed', $currency, [
            'old_code' => $oldCode,
            'new_code' => $currency->code,
        ]);

        return response()->json(['message' => __('api.admin.currency_base_updated')]);
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
