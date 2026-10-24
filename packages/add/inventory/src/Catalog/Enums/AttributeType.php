<?php

namespace Add\Inventory\Catalog\Enums;

enum AttributeType: string
{
    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
    case Date = 'date';
    // One of the definition's `options`.
    case Select = 'select';
}
