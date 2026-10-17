<?php

namespace App\Domain\Ecosystem\Enums;

/**
 * What an announcement is about. Was an open string (a new kind was a row,
 * docs/decisions/kiosk-display.md); it is a closed set now, because the
 * kiosk and the member app both switch on it, and a kind typed into the
 * dashboard that neither knows had nowhere to go.
 */
enum AnnouncementType: string
{
    case Offer = 'offer';
    case Event = 'event';
    case News = 'news';

    public function label(?string $locale = null): string
    {
        return __('api.announcements.types.'.$this->value, [], $locale);
    }
}
