<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\Currency;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;

/**
 * Deliberately a plain resolver, not middleware — unlike the `lang`
 * header's SetLocaleFromHeader/SetLocaleFromUserPreference pair, there is
 * no auth-timing ordering trap here: PlanResource already resolves the
 * user itself, synchronously, at the exact point it needs this value.
 *
 * The `currency` header now validates against active rows in the
 * admin-managed `currencies` table rather than a hardcoded enum
 * (docs/decisions/multi-currency-support.md), and the final fallback is
 * whichever currency has `is_base = true`, not a hardcoded 'SYP' literal.
 */
class CurrencyResolver
{
    public function resolve(Request $request, ?User $user): string
    {
        $header = strtoupper((string) $request->header('currency'));

        if ($header !== '' && Currency::query()->where('code', $header)->where('is_active', true)->exists()) {
            return $header;
        }

        return $user?->preferred_currency ?? Currency::query()->where('is_base', true)->value('code');
    }
}
