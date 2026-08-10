<?php

namespace App\Domain\Finance\Enums;

/**
 * The only two currencies `exchange_rates` models (Unit 1 design,
 * 2026-08-09) — the single source of truth for the literal values
 * previously duplicated across validation rules and
 * CurrencyConversionService. Validation only; `preferred_currency` and
 * `pricing_currency` remain plain string columns, not enum casts.
 */
enum Currency: string
{
    case Usd = 'USD';
    case Syp = 'SYP';
}
