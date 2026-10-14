<?php

namespace App\Http\Resources;

use App\Domain\Finance\Services\CurrencyConversionService;
use App\Domain\Finance\Services\PointConversionService;
use Illuminate\Http\Request;

/**
 * The user as a member session hands it over — register/verify, login,
 * refresh and reactivation — plus the rates the app keeps for the whole
 * session: how many SYP one USD buys, and what one point is worth in USD.
 * Read live on every issue, so a new rate reaches the app at its next
 * refresh. Only these responses carry it; `me` and the admin screens keep
 * plain UserResource.
 *
 * Unlike money amounts elsewhere, these two go out as JSON numbers (140,
 * 0.1), as the member app asked — it keeps them for display, not exact
 * arithmetic.
 */
class AuthenticatedUserResource extends UserResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'rates' => [
                'usd_to_syp' => self::number(app(CurrencyConversionService::class)->baseToCurrencyRate('SYP')),
                'point_rate_usd' => self::number(app(PointConversionService::class)->plainRate()),
            ],
        ];
    }

    /**
     * "140" → 140 and "0.1" → 0.1: a whole value stays an int, so it is
     * not encoded as 140.0.
     */
    private static function number(?string $value): int|float|null
    {
        return $value === null ? null : $value + 0;
    }
}
