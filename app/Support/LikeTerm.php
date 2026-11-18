<?php

namespace App\Support;

/**
 * A user's search text made safe for `LIKE ? ESCAPE '!'` — `!` rather than a
 * backslash: the one escape character MySQL and SQLite both read the same way.
 */
final class LikeTerm
{
    public static function contains(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
    }
}
