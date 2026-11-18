<?php

namespace App\Support\Plans;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Enums\CoworkingSeat;

/**
 * One benefit per target (product owner, 2026-11-02): a space, a space type
 * or the whole plan takes free hours or a discount — once, across both
 * lists. A coworking plan takes no benefit on coworking itself: its seat
 * and hours already are that.
 */
final class PlanBenefitTargets
{
    /**
     * @param  list<array<string, mixed>>  $discounts
     * @param  list<array<string, mixed>>  $spaceHours
     * @return array<string, string> field => message key
     */
    public static function errors(array $discounts, array $spaceHours, CoworkingSeat $seat): array
    {
        $errors = [];
        $seen = [];
        $coworkingSpaceIds = Space::query()
            ->whereIn('id', array_filter(array_column([...$discounts, ...$spaceHours], 'space_id')))
            ->where('space_type', SpaceType::CoSpace)
            ->pluck('id')
            ->all();

        foreach (['discounts' => $discounts, 'space_hours' => $spaceHours] as $list => $rows) {
            foreach (array_values($rows) as $i => $row) {
                $spaceId = $row['space_id'] ?? null;
                $type = $row['space_type'] ?? null;
                $type = $type instanceof SpaceType ? $type->value : $type;
                $field = $spaceId !== null ? "{$list}.{$i}.space_id" : "{$list}.{$i}.space_type";

                if ($seat !== CoworkingSeat::None
                    && ($type === SpaceType::CoSpace->value || in_array((int) $spaceId, $coworkingSpaceIds, true))) {
                    $errors[$field] = 'api.admin.plan_coworking_benefit_on_coworking_plan';

                    continue;
                }

                $target = $spaceId !== null ? "space:{$spaceId}" : ($type !== null ? "type:{$type}" : 'plan');

                if (isset($seen[$target])) {
                    $errors[$field] = 'api.admin.plan_benefit_duplicate_target';
                }

                $seen[$target] = true;
            }
        }

        return $errors;
    }
}
