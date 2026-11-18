<?php

namespace Add\Hr\Core\Services;

use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Support\HrException;
use Illuminate\Database\Eloquent\Collection;

/**
 * The organization tree's invariants and shapes. Units are few (tens, not
 * thousands), so walks load the whole table once instead of recursing in SQL.
 */
class OrgTree
{
    /**
     * A unit's new parent may be neither the unit itself nor anything below it.
     */
    public function assertValidParent(OrgUnit $unit, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($parentId === $unit->id || in_array($parentId, $this->descendantIds($unit), true)) {
            HrException::fail('hr::core.org_unit_cycle');
        }
    }

    /**
     * @return list<int>
     */
    public function descendantIds(OrgUnit $unit): array
    {
        $childrenOf = OrgUnit::query()->get(['id', 'parent_id'])->groupBy('parent_id');

        $ids = [];
        $queue = [$unit->id];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($childrenOf->get($current, []) as $child) {
                $ids[] = $child->id;
                $queue[] = $child->id;
            }
        }

        return $ids;
    }

    /**
     * Every unit, as root units with `children` relations set recursively.
     *
     * @return Collection<int, OrgUnit>
     */
    public function nested(): Collection
    {
        $units = OrgUnit::query()->orderBy('id')->get();
        $byParent = $units->groupBy('parent_id');

        foreach ($units as $unit) {
            $unit->setRelation('children', new Collection($byParent->get($unit->id, collect())->all()));
        }

        return new Collection($byParent->get('', collect())->all());
    }
}
