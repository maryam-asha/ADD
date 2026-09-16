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
     * The same, for a list of nested rows — `sessions.*.start_at`.
     *
     * A session's hours are typed by the same admin in the same box as an
     * event's, so they mean the same thing and have to be read the same way.
     * Doing it here rather than in the service keeps every wall clock in this
     * application interpreted at the edge, which is what the guard in
     * tests/Guards/UserSuppliedTimesAreInterpretedLocallyTest exists to hold.
     *
     * @param  list<string>  $fields
     */
    protected function interpretLocalTimesIn(string $key, array $fields): void
    {
        $rows = $this->input($key);

        if (! is_array($rows)) {
            return;
        }

        $local = app(LocalTime::class);

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach ($fields as $field) {
                $value = $row[$field] ?? null;

                if (! is_string($value) || trim($value) === '') {
                    continue;
                }

                $rows[$index][$field] = $local->toUtc($value)->toDateTimeString();
            }
        }

        $this->merge([$key => $rows]);
    }

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
