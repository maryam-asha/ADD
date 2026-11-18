<?php

namespace Add\Hr\Core\Models;

use Add\Hr\Core\Enums\ChecklistAssignee;
use Add\Hr\Core\Enums\ChecklistKind;
use Add\Hr\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * What to do when someone joins or leaves. Every active template of the
 * kind is copied into tasks at the hire or termination.
 */
class ChecklistTemplate extends Model
{
    use HasTranslations;

    protected $table = 'hr_checklist_templates';

    protected $fillable = [
        'kind',
        'name',
        'items',
        'is_active',
    ];

    protected $casts = [
        'kind' => ChecklistKind::class,
        'name' => 'array',
        'items' => 'array',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Indexes of the items an employee could never do: an employee's
     * leaving task due after the last working day, when hr/me no longer
     * lets them in. Joining has no such end.
     *
     * @param  array<int, mixed>  $items
     * @return list<int>
     */
    public static function employeeItemsAfterLastDay(?ChecklistKind $kind, array $items): array
    {
        if ($kind !== ChecklistKind::Offboarding) {
            return [];
        }

        return array_keys(array_filter($items, fn ($item) => is_array($item)
            && ($item['assignee'] ?? null) === ChecklistAssignee::Employee->value
            && is_numeric($item['due_offset_days'] ?? null)
            && (int) $item['due_offset_days'] > 0));
    }
}
