<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * Every money amount leaves the API without trailing zeros ("300", not
 * "300.00"). Money columns use App\Casts\PlainDecimal; `decimal:2` is only
 * for the two quantity columns, which are not money.
 */
class MoneyCastsArePlainTest extends TestCase
{
    use ScansSourceFiles;

    private const ALLOWED = [
        'Membership/Models/PlanResourceGrant.php',
        'Membership/Models/ResourceConsumption.php',
    ];

    public function test_no_model_casts_money_as_decimal_2(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(base_path('app/Domain')) as $path => $contents) {
            $normalised = str_replace(chr(92), '/', $path);

            if (! str_contains($normalised, '/Models/') || ! str_contains($contents, "'decimal:2'")) {
                continue;
            }

            foreach (self::ALLOWED as $allowed) {
                if (str_ends_with($normalised, $allowed)) {
                    continue 2;
                }
            }

            $violations[] = $path;
        }

        $this->assertSame([], $violations, "Use PlainDecimal for money columns, not 'decimal:2':\n".implode("\n", $violations));
    }
}
