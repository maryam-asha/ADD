<?php

namespace Add\Hr\Core\Enums;

/**
 * Who does a checklist task: HR (the default), or the employee it is about,
 * who then sees and ticks it in self-service.
 */
enum ChecklistAssignee: string
{
    case Hr = 'hr';
    case Employee = 'employee';
}
