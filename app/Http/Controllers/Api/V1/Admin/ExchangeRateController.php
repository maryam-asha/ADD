<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Finance\Enums\ExchangeRateSource;
use App\Domain\Finance\Enums\ExchangeRateSuggestionStatus;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreExchangeRateRequest;
use App\Http\Resources\ExchangeRateResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

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
        $suggestion = null;

        $rate = DB::transaction(function () use ($request, &$suggestion) {
            if ($request->filled('suggestion_id')) {
                // Re-queried and locked here, inside the transaction — never
                // trust the validation layer's earlier read for a decision
                // this consequential. Same lock-then-recheck shape as
                // SessionClosureService::autoClose() and
                // WalkInCapacityService::start(): re-query by key under
                // lockForUpdate(), decide only against what the lock sees.
                $suggestion = ExchangeRateSuggestion::query()
                    ->whereKey($request->input('suggestion_id'))
                    ->lockForUpdate()
                    ->first();

                abort_if(
                    ! $suggestion || $suggestion->status !== ExchangeRateSuggestionStatus::Pending,
                    422,
                    __('api.admin.exchange_rate_suggestion_not_pending')
                );
            }

            $rate = ExchangeRate::create([
                'currency_code' => $request->input('currency_code'),
                'rate_to_base' => $request->input('rate_to_base'),
                'effective_from' => $request->input('effective_from'),
                'set_by' => $request->user()->id,
                'source' => $suggestion ? ExchangeRateSource::ExternalAccepted : ExchangeRateSource::Manual,
                'suggestion_id' => $suggestion?->id,
            ]);

            $suggestion?->update([
                'status' => ExchangeRateSuggestionStatus::Accepted,
                'accepted_rate_id' => $rate->id,
            ]);

            return $rate;
        });

        $this->logSensitiveAction('exchange_rate_created', $rate, array_filter([
            'currency_code' => $rate->currency_code,
            'rate_to_base' => $rate->rate_to_base,
            'effective_from' => $rate->effective_from->toISOString(),
            'suggestion_id' => $suggestion?->id,
            'suggested_rate_usd_to_syp' => $suggestion?->rate_usd_to_syp,
        ], fn ($value) => $value !== null));

        return new ExchangeRateResource($rate);
    }
}
