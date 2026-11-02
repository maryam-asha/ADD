<?php

namespace App\Domain\Membership\Support;

use App\Domain\Membership\Enums\CoworkingSeat;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanModule;
use App\Domain\Membership\Models\PlanResourceGrant;

/**
 * How a plan made before plans stated their coworking terms reads in them
 * (product owner, 2026-11-02): the dedicated-desk module is a dedicated
 * desk (always unlimited); a space-hours grant is a flexible seat with that
 * many hours; anything else does not include coworking. The conversion
 * migration and the review report (plans:coworking-report) both read
 * plans through here, so what was reviewed is what runs.
 *
 * Reads only the old shape (module slug, the space-hours grant), so the
 * report works before the conversion has run.
 */
class CoworkingPlanClassifier
{
    /** The resource type that carried coworking hours before plans had their own column. */
    public const LEGACY_HOURS_SLUG = 'space-hours';

    /**
     * @return array{seat: CoworkingSeat, hours: ?string, flags: list<string>}
     */
    public function classify(Plan $plan): array
    {
        $grant = $this->hoursGrant($plan);
        $hasWindow = $plan->hasAccessWindow();
        $flags = [];

        if ($plan->planModule?->slug === PlanModule::DEDICATED_DESK) {
            if ($grant !== null) {
                $flags[] = 'dedicated_with_hours';
            }

            return ['seat' => CoworkingSeat::Dedicated, 'hours' => null, 'flags' => $flags];
        }

        if ($grant !== null) {
            if (! $hasWindow) {
                // Never covered anyone before (no window, no coverage);
                // from now on it covers all day.
                $flags[] = 'hours_without_window';
            }

            // The old catalog stood in for "unlimited" with every hour its
            // window opens over the period; that is unlimited now.
            $hours = $hasWindow && (float) $grant->quantity >= $this->windowHoursOver($plan)
                ? null
                : number_format((float) $grant->quantity, 2, '.', '');

            return ['seat' => CoworkingSeat::Flexible, 'hours' => $hours, 'flags' => $flags];
        }

        if ($hasWindow) {
            // A window but no hours: it held a seat in the pool and covered
            // nothing. Converted to "no coworking" — for a person to check.
            $flags[] = 'window_without_hours';
        }

        return ['seat' => CoworkingSeat::None, 'hours' => null, 'flags' => $flags];
    }

    /** Every hour the plan's daily window opens over its whole period; a window whose end is its start is all day. */
    private function windowHoursOver(Plan $plan): float
    {
        $minutes = fn (string $time) => ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
        $open = $minutes($plan->access_window_end) - $minutes($plan->access_window_start);

        return (int) $plan->duration_days * (($open > 0 ? $open : $open + 24 * 60) / 60);
    }

    public function hoursGrant(Plan $plan): ?PlanResourceGrant
    {
        return $plan->resourceGrants()
            ->whereHas('resourceType', fn ($query) => $query->where('slug', self::LEGACY_HOURS_SLUG))
            ->first();
    }
}
