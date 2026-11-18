<?php

namespace Add\Hr\Performance\Enums;

enum AnswerKind: string
{
    /** item_key is the competency's code. */
    case Competency = 'competency';
    /** item_key is the goal's id. */
    case Goal = 'goal';
}
