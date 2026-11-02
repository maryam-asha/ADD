<?php

namespace Add\Hr\Core\Services;

use Add\Hr\Core\Enums\ChecklistAssignee;
use Add\Hr\Core\Enums\ChecklistKind;
use Add\Hr\Core\Models\ChecklistTemplate;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\EmploymentEvent;

class ChecklistService
{
    /**
     * Copy every active template of the kind into the employee's tasks, each
     * due its offset after the event's date. Returns how many were created.
     */
    public function createFor(Employee $employee, ChecklistKind $kind, EmploymentEvent $event): int
    {
        $sort = 0;
        $templates = ChecklistTemplate::query()->where('kind', $kind->value)->where('is_active', true)->orderBy('id')->get();

        foreach ($templates as $template) {
            foreach ($template->items as $item) {
                $employee->checklistTasks()->create([
                    'kind' => $kind,
                    'assignee' => $item['assignee'] ?? ChecklistAssignee::Hr->value,
                    'event_id' => $event->id,
                    'title' => $item['title'],
                    'due_on' => $event->effective_on->addDays((int) ($item['due_offset_days'] ?? 0)),
                    'sort' => $sort++,
                ]);
            }
        }

        return $sort;
    }
}
