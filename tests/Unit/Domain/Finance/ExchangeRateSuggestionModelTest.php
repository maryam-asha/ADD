<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Enums\ExchangeRateSource;
use App\Domain\Finance\Enums\ExchangeRateSuggestionSource;
use App\Domain\Finance\Enums\ExchangeRateSuggestionStatus;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateSuggestionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_suggestion_can_be_created_with_its_default_factory_state(): void
    {
        $suggestion = ExchangeRateSuggestion::factory()->create();

        $this->assertSame(ExchangeRateSuggestionSource::SpToday, $suggestion->source);
        $this->assertSame(ExchangeRateSuggestionStatus::Pending, $suggestion->status);
        $this->assertSame('13275.0000000000', $suggestion->rate_usd_to_syp);
        $this->assertIsArray($suggestion->raw_payload);
    }

    public function test_an_exchange_rate_defaults_to_manual_source_with_no_suggestion(): void
    {
        $rate = ExchangeRate::factory()->create();

        $this->assertSame(ExchangeRateSource::Manual, $rate->source);
        $this->assertNull($rate->suggestion_id);
    }

    public function test_an_exchange_rate_can_link_back_to_the_suggestion_it_came_from(): void
    {
        $suggestion = ExchangeRateSuggestion::factory()->create();
        $rate = ExchangeRate::factory()->create([
            'source' => ExchangeRateSource::ExternalAccepted,
            'suggestion_id' => $suggestion->id,
        ]);

        $this->assertTrue($rate->suggestion->is($suggestion));
    }
}
