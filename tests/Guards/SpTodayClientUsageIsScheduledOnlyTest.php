<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * docs/decisions/exchange-rate-external-suggestion.md: SpTodayRateClient may
 * only ever be reached from the scheduled command that fetches suggestions,
 * never from anything a request could reach — a controller, Form Request,
 * middleware, or API Resource calling it directly would be exactly the kind
 * of external-source-as-authority shortcut the feature exists to prevent.
 */
class SpTodayClientUsageIsScheduledOnlyTest extends TestCase
{
    use ScansSourceFiles;

    private const ALLOWED_FILES = [
        'app/Console/Commands/FetchExchangeRateSuggestion.php',
        'app/Domain/Finance/Services/ExchangeRateSuggestionIngestor.php',
        'app/Domain/Finance/Services/SpTodayRateClient.php',
    ];

    public function test_sptodayrateclient_is_referenced_only_from_the_scheduled_ingestion_path(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(base_path('app')) as $path => $contents) {
            if (in_array($path, self::ALLOWED_FILES, true)) {
                continue;
            }

            if (str_contains($contents, 'SpTodayRateClient')) {
                $violations[] = "{$path} references SpTodayRateClient";
            }
        }

        $this->assertSame([], $violations, "SpTodayRateClient may only be used by the scheduled ingestion path:\n".implode("\n", $violations));
    }
}
