<?php

namespace App\Support\Presenters;

use App\Domain\Experience\Models\Event;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Services\OfferResolver;

/**
 * The `sale` block on an event card — the same shape as on spaces and
 * packages: the best automatic offer live now for this member, the price
 * struck through, what it takes off and when it ends; null when there is
 * none.
 *
 * Here, outside the domains, because Domain\Experience may not import
 * Domain\Pricing (tests/Guards/DomainLayerBoundaryTest.php). Not a
 * singleton: OfferResolver reads the active offers once per instance, so
 * one instance serves a listing's cards, and the next request reads afresh.
 */
class EventSalePresenter
{
    public function __construct(
        private readonly OfferResolver $offers,
        private readonly PriceLabelPresenter $labels,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function for(Event $event, ?User $user, string $locale): ?array
    {
        return $this->labels->sale($this->offers->eventNow($event, $user), (int) ($event->price_points ?? 0), $user, false, 'api.event', $locale);
    }
}
