<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Finance\Enums\ExchangeRateSuggestionStatus;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class ExchangeRateSuggestionController extends Controller
{
    use LogsSensitiveActions;

    public function show(): JsonResponse
    {
        $suggestion = ExchangeRateSuggestion::where('status', ExchangeRateSuggestionStatus::Pending)
            ->latest('fetched_at')
            ->first();

        $lastSuccessfulFetchAt = ExchangeRateSuggestion::max('fetched_at');
        $sourceStale = $lastSuccessfulFetchAt === null
            || Carbon::parse($lastSuccessfulFetchAt)->lt(now()->subHours(48));

        return response()->json([
            'id' => $suggestion?->id,
            'rate_usd_to_syp' => $suggestion?->rate_usd_to_syp,
            'source' => $suggestion?->source?->value,
            'fetched_at' => $suggestion?->fetched_at?->toISOString(),
            'deviation_percent' => $this->deviationPercent($suggestion),
            'source_stale' => $sourceStale,
            'last_successful_fetch_at' => $lastSuccessfulFetchAt ? Carbon::parse($lastSuccessfulFetchAt)->toISOString() : null,
        ]);
    }

    /**
     * Both numbers must face the same direction before comparing —
     * rate_to_base is USD-per-1-SYP, rate_usd_to_syp is SYP-per-1-USD. See
     * docs/decisions/exchange-rate-external-suggestion.md, "the direction
     * problem".
     */
    private function deviationPercent(?ExchangeRateSuggestion $suggestion): ?float
    {
        if (! $suggestion) {
            return null;
        }

        $current = ExchangeRate::current('SYP');

        if (! $current) {
            return null;
        }

        $currentSypPerUsd = 1 / (float) $current->rate_to_base;

        return round((((float) $suggestion->rate_usd_to_syp - $currentSypPerUsd) / $currentSypPerUsd) * 100, 2);
    }
}
