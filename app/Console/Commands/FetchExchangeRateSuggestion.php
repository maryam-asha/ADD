<?php

namespace App\Console\Commands;

use App\Domain\Finance\Services\ExchangeRateSuggestionIngestor;
use Illuminate\Console\Command;

class FetchExchangeRateSuggestion extends Command
{
    protected $signature = 'finance:fetch-exchange-rate-suggestion';

    protected $description = 'Fetch the daily sp-today USD/SYP quote as a pending exchange-rate suggestion for an admin to review.';

    public function handle(ExchangeRateSuggestionIngestor $ingestor): int
    {
        $ingestor->run();

        return self::SUCCESS;
    }
}
