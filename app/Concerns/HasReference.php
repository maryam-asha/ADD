<?php

namespace App\Concerns;

use App\Support\ReferenceSequence;
use Carbon\CarbonInterface;

/**
 * A record's stored, human-readable number ("BK-2026-00041"), given on
 * create whichever path makes the row: the model's prefix, the year, and a
 * gap-free counter per prefix and year (ReferenceSequence). A reference set
 * before saving is kept.
 *
 * The model names its prefix; the year is that of `created_at` unless it
 * says otherwise (referenceDate()). Its table needs a unique `reference`
 * column, backfilled for rows that existed before it.
 */
trait HasReference
{
    /**
     * The series' prefix, without the dash: "BK", "TRX".
     */
    abstract protected function referencePrefix(): string;

    public static function bootHasReference(): void
    {
        static::creating(function (self $model) {
            if ($model->reference !== null) {
                return;
            }

            $key = $model->referencePrefix().'-'.$model->referenceDate()->format('Y');

            $model->reference = sprintf('%s-%05d', $key, ReferenceSequence::next($key));
        });
    }

    /**
     * The moment whose year the series restarts on.
     */
    protected function referenceDate(): CarbonInterface
    {
        return $this->created_at ?? now();
    }
}
