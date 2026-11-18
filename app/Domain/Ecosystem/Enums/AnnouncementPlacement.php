<?php

namespace App\Domain\Ecosystem\Enums;

/** Which screen an announcement is shown on: the reception kiosk, the member app's home, or both. */
enum AnnouncementPlacement: string
{
    case Kiosk = 'kiosk';
    case App = 'app';
    case All = 'all';

    public function label(?string $locale = null): string
    {
        return __('api.announcements.placements.'.$this->value, [], $locale);
    }
}
