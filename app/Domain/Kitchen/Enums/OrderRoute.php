<?php

namespace App\Domain\Kitchen\Enums;

/**
 * Where a new order goes first: straight to the kitchen board, or to
 * reception, which decides whether it may reach the kitchen at all.
 */
enum OrderRoute: string
{
    case Kitchen = 'kitchen';
    case Reception = 'reception';
}
