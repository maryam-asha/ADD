<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Finance\Models\ExchangeRate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreExchangeRateRequest;
use App\Http\Resources\ExchangeRateResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Doesn't extend AdminResourceController — no `order` column, and no
 * update/destroy: a rate row is never mutated once written (Unit 1
 * design, 2026-08-09), same reasoning UserController uses for its own
 * deviations from the generic pattern.
 */
class ExchangeRateController extends Controller
{
    use LogsSensitiveActions;

    public function index(): AnonymousResourceCollection
    {
        return ExchangeRateResource::collection(
            ExchangeRate::query()->orderByDesc('effective_from')->get()
        );
    }

    public function store(StoreExchangeRateRequest $request): ExchangeRateResource
    {
        $rate = ExchangeRate::create([
            ...$request->validated(),
            'set_by' => $request->user()->id,
        ]);

        $this->logSensitiveAction('exchange_rate_created', $rate, [
            'currency_code' => $rate->currency_code,
            'rate_to_base' => $rate->rate_to_base,
            'effective_from' => $rate->effective_from->toISOString(),
        ]);

        return new ExchangeRateResource($rate);
    }
}
