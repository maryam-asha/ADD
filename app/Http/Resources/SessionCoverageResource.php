<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Renders a SessionCoverage::toArray() snapshot. The plan name is stored in
 * both languages; the reader's is picked here with HasTranslations' own
 * fallback order (current locale → en → ar).
 */
class SessionCoverageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->resource;
        $plan = $snapshot['plan'] ?? null;

        if (is_array($plan) && is_array($plan['name'] ?? null)) {
            $names = $plan['name'];
            $plan['name'] = $names[app()->getLocale()] ?? $names['en'] ?? $names['ar'] ?? null;
        }

        return array_merge($snapshot, ['plan' => $plan]);
    }
}
