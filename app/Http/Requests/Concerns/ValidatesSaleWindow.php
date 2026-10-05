<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Model;

/**
 * A sale on a space or a plan, as the admin types it: a sale price and the
 * window it runs, all three together or none — sending all three empty ends
 * it, leaving them out keeps it. The window is local time, interpreted at
 * the edge like every other typed wall clock; the using request also uses
 * InterpretsLocalTimes and calls interpretSaleWindow() from
 * prepareForValidation() (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 */
trait ValidatesSaleWindow
{
    protected function interpretSaleWindow(): void
    {
        $this->interpretLocalTimes(['sale_starts_at', 'sale_ends_at']);
    }

    /**
     * @return array<string, list<string>>
     */
    protected function saleWindowRules(string $saleField): array
    {
        $fields = [$saleField, 'sale_starts_at', 'sale_ends_at'];
        $withTheOthers = fn (string $field) => 'required_with:'.implode(',', array_diff($fields, [$field]));

        return [
            $saleField => ['nullable', 'integer', 'min:0', $withTheOthers($saleField)],
            'sale_starts_at' => ['nullable', 'date', $withTheOthers('sale_starts_at')],
            'sale_ends_at' => ['nullable', 'date', 'after:sale_starts_at', $withTheOthers('sale_ends_at')],
        ];
    }

    /**
     * A sale is below the price it discounts — each side as sent, or else as
     * stored, so lowering the price under a running sale is refused too. A
     * stored sale that has already ended is history, not a price to stay
     * under.
     */
    protected function assertSaleBelowPrice(Validator $validator, string $saleField, string $priceField, ?Model $stored): void
    {
        if ($validator->errors()->hasAny([$saleField, $priceField, 'sale_ends_at'])) {
            return;
        }

        $saleSent = $this->exists($saleField);
        $sale = $saleSent ? $this->input($saleField) : $stored?->getAttribute($saleField);

        if ($sale === null || (! $saleSent && $stored?->getAttribute('sale_ends_at')?->isPast())) {
            return;
        }

        $price = $this->exists($priceField) ? $this->input($priceField) : $stored?->getAttribute($priceField);

        if ($price === null || (int) $sale >= (int) $price) {
            $validator->errors()->add($saleField, __('api.admin.sale_not_below_price'));
        }
    }
}
