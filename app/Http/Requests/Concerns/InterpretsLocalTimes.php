<?php

namespace App\Http\Requests\Concerns;

use App\Support\Time\LocalTime;

/**
 * Reads incoming wall-clock times the way the person entering them meant.
 *
 * A bare "2026-10-01 19:00" means 19:00 in the district, not 19:00 UTC —
 * three hours apart, which is enough to push a booking outside business
 * hours or move an event onto the next day. A value that states its own
 * offset ("+03:00", "Z") is left alone: that client has already been
 * explicit, and re-interpreting it would shift the time again.
 *
 * Call from prepareForValidation(), so the value is normalised before any
 * rule (`after:start_at`, `before_or_equal:now`) compares it.
 */
trait InterpretsLocalTimes
{
    /**
     * @param  list<string>  $fields
     */
    protected function interpretLocalTimes(array $fields): void
    {
        $local = app(LocalTime::class);

        foreach ($fields as $field) {
            $value = $this->input($field);

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $this->merge([
                $field => $local->toUtc($value)->toDateTimeString(),
            ]);
        }
    }
}
