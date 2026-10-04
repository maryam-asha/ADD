<?php

namespace App\Domain\Pricing\Concerns;

use App\Domain\Pricing\ValueObjects\SaleWindow;

/**
 * An item with a sale stored on itself: a price column named by
 * salePriceColumn(), plus `sale_starts_at` / `sale_ends_at` — one sale per
 * item at a time (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 */
trait HasSalePrice
{
    abstract protected function salePriceColumn(): string;

    public function saleWindow(): ?SaleWindow
    {
        $points = $this->getAttribute($this->salePriceColumn());

        return SaleWindow::of(
            $points === null ? null : (int) $points,
            $this->getAttribute('sale_starts_at'),
            $this->getAttribute('sale_ends_at'),
        );
    }
}
