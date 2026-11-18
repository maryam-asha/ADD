<?php

namespace App\Http\Requests\Concerns;

/**
 * Reads a null sent for a NOT NULL field that has a default as "not sent".
 *
 * A form posts its empty boxes as null (or as "", which Laravel turns into
 * null), and `nullable` lets that through — so `array_merge($defaults,
 * $validated)` in a `store()` put the null back over the default and the
 * insert failed on the NOT NULL column. Dropping the key instead lets
 * creating fall back to the controller's default and updating leave the
 * stored value alone.
 *
 * Call from prepareForValidation().
 */
trait DropsNullDefaults
{
    /**
     * @param  list<string>  $fields
     */
    protected function dropNullDefaults(array $fields): void
    {
        foreach ($fields as $field) {
            if ($this->has($field) && $this->input($field) === null) {
                $this->request->remove($field);
                $this->json()->remove($field);
            }
        }
    }
}
