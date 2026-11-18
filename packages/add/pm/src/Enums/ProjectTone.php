<?php

namespace Add\Pm\Enums;

/**
 * The colour family a project or tag is shown in. Only the name is stored;
 * the dashboard maps it to its own design tokens.
 */
enum ProjectTone: string
{
    case Olive = 'olive';
    case Teal = 'teal';
    case Slate = 'slate';
    case Green = 'green';
    case Cyan = 'cyan';
    case Stone = 'stone';
}
