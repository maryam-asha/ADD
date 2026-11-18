<?php

namespace App\Http\Requests\Admin\Concerns;

use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * Every action a role is granted brings the `view` of its module with it —
 * `amenities.create` adds `amenities.view`, `hr.employees.update` adds
 * `hr.employees.view` (the last segment becomes `view`) — when that view
 * exists. Acting on a module whose screen and list the role cannot open is
 * no grant at all (docs/superpowers/specs/2026-10-25-dashboard-screen-permissions-design.md,
 * 2026-10-27-permission-only-dashboard-access-design.md).
 */
trait GrantsModuleViews
{
    /**
     * The validated `permissions`, plus the view each one implies; null when
     * the request sent none.
     *
     * @return list<string>|null
     */
    public function grantedPermissions(): ?array
    {
        $granted = $this->validated('permissions');

        if ($granted === null) {
            return null;
        }

        $implied = collect($granted)
            ->filter(fn (string $name) => str_contains($name, '.'))
            ->map(fn (string $name) => Str::beforeLast($name, '.').'.view')
            ->diff($granted)
            ->unique()
            ->values();

        $views = $implied->isEmpty()
            ? []
            : Permission::query()->where('guard_name', 'web')->whereIn('name', $implied)->pluck('name')->all();

        return array_values(array_unique([...$granted, ...$views]));
    }
}
