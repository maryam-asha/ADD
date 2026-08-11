<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\Currency;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;

/**
 * Deliberately a plain resolver, not middleware — unlike the `lang`
 * header's SetLocaleFromHeader/SetLocaleFromUserPreference pair, there is
 * no auth-timing ordering trap here: PlanResource already resolves the
 * user itself, synchronously, at the exact point it needs this value.
 */
class CurrencyResolver
{
    public function resolve(Request $request, ?User $user): string
    {
        $header = strtoupper((string) $request->header('currency'));

        if (Currency::tryFrom($header) !== null) {
            return $header;
        }

        return $user?->preferred_currency ?? Currency::Syp->value;
    }
}
