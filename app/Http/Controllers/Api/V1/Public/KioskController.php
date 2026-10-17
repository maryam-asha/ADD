<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Ecosystem\Enums\AnnouncementPlacement;
use App\Domain\Ecosystem\Models\Announcement;
use App\Domain\Ecosystem\Models\ContactLink;
use App\Domain\Membership\Services\PackageBrowseService;
use App\Domain\Settings\Services\SettingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * One unauthenticated aggregate for the reception kiosk screen
 * (docs/decisions/kiosk-display.md). Deliberately does not extend
 * PublicResourceController — it aggregates four independent sources into a
 * sectioned shape rather than listing one resource. Every section's field
 * set is locked to the decision doc's sample response, which is narrower
 * than this app's general-purpose Resources for the same models — so this
 * builds plain arrays instead of reusing them. `plans` is the exception: it
 * is the member app's package catalog unchanged (PackageBrowseService).
 */
class KioskController extends Controller
{
    public function show(SettingService $settings, PackageBrowseService $packages): JsonResponse
    {
        return response()->json(['data' => [
            'banner' => $this->liveAnnouncements(),
            // The member app's package catalog (member/packages), item for item.
            'plans' => $packages->listPublicPackages(),
            'social_links' => $this->visibleSocialLinks(),
            'app_download' => [
                'app_store' => $settings->get('kiosk.app_store_url', 'https://apps.example.local/ios'),
                'google_play' => $settings->get('kiosk.google_play_url', 'https://apps.example.local/android'),
            ],
            'arrival_qr' => ['value' => $settings->get('kiosk.arrival_qr_value', 'addapp://arrival')],
        ]]);
    }

    /**
     * One flat, mixed-type list — the client switches on each item's own
     * `type`, rather than being handed three separately-keyed lists.
     *
     * @return array<int, array{id: int, type: string, image_url: string, link_url: ?string}>
     */
    private function liveAnnouncements(): array
    {
        return Announcement::query()
            ->live()
            ->shownOn(AnnouncementPlacement::Kiosk)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Announcement $announcement) => [
                'id' => $announcement->id,
                'type' => $announcement->type->value,
                'image_url' => $announcement->image_url,
                'link_url' => $announcement->link_url,
            ])
            ->all();
    }

    /**
     * @return array<int, array{type: string, value: string, label: ?string}>
     */
    private function visibleSocialLinks(): array
    {
        return ContactLink::query()
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ContactLink $link) => [
                'type' => $link->type,
                'value' => $link->value,
                'label' => $link->label,
            ])
            ->all();
    }
}
