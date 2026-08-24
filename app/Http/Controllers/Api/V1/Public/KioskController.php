<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Ecosystem\Models\Announcement;
use App\Domain\Ecosystem\Models\ContactLink;
use App\Domain\Membership\Models\Plan;
use App\Domain\Settings\Services\SettingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * One unauthenticated aggregate for the reception kiosk screen
 * (docs/decisions/kiosk-display.md). Deliberately does not extend
 * PublicResourceController — it aggregates four independent sources into a
 * sectioned shape rather than listing one resource. Every section's field
 * set is locked to the decision doc's sample response, which is narrower
 * than this app's general-purpose Resources for the same models (e.g. no
 * currency-conversion side effect from PlanResource) — so this builds plain
 * arrays instead of reusing them.
 */
class KioskController extends Controller
{
    public function show(SettingService $settings): JsonResponse
    {
        return response()->json([
            'banner' => $this->liveAnnouncements(),
            'plans' => $this->activePlans(),
            'social_links' => $this->visibleSocialLinks(),
            'app_download' => ['url' => $settings->get('kiosk.app_download_url', 'https://example.local/download')],
            'arrival_qr' => ['value' => $settings->get('kiosk.arrival_qr_value', 'addapp://arrival')],
        ]);
    }

    /**
     * One flat, mixed-type list — the client switches on each item's own
     * `type`, rather than being handed three separately-keyed lists.
     *
     * @return array<int, array{id: int, type: string, image_url: string, link_url: ?string}>
     */
    private function liveAnnouncements(): array
    {
        $now = now();

        return Announcement::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Announcement $announcement) => [
                'id' => $announcement->id,
                'type' => $announcement->type,
                'image_url' => $announcement->image_url,
                'link_url' => $announcement->link_url,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activePlans(): array
    {
        return Plan::query()
            ->where('is_active', true)
            ->orderBy('order')
            ->get()
            ->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'price' => (string) $plan->price,
                'pricing_currency' => $plan->pricing_currency,
                'duration_days' => $plan->duration_days,
                'included_hours' => (string) $plan->included_hours,
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
