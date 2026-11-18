<?php

namespace Tests\Feature\Experience;

use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Services\EventBrowseService;
use App\Domain\Finance\Models\Currency;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Listing events must not cost a query per event.
 *
 * A page of 20 fired 153 queries, of which 147 were repeats — and none of
 * them were about events. Every card resolved the base currency and the
 * currency's symbol from the database on its own, and with CACHE_STORE set to
 * `database` in production even the already-cached lookups cost a query each.
 *
 * The count is asserted as a ceiling rather than an exact figure: a new eager
 * load or a changed card field would otherwise fail this test for no reason,
 * while a reintroduced N+1 still trips it.
 */
class EventBrowseQueryCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        Currency::query()->updateOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'is_base' => true, 'is_active' => true, 'is_default' => true]
        );
    }

    /** @return array{count: int, queries: list<string>} */
    private function countQueries(callable $work): array
    {
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        $work();

        DB::flushQueryLog();

        return ['count' => count($queries), 'queries' => $queries];
    }

    public function test_listing_events_does_not_scale_its_queries_with_the_page_size(): void
    {
        Event::factory()->count(20)->create(['status' => 'published']);

        $result = $this->countQueries(
            fn () => app(EventBrowseService::class)->browse(['per_page' => 20], null)
        );

        $this->assertLessThanOrEqual(
            30,
            $result['count'],
            "Listing 20 events took {$result['count']} queries. Something is being "
            .'resolved per card instead of once for the page.'
        );
    }

    /**
     * The specific regression: the base currency is one fact for the whole
     * request, and it was being fetched again for every card.
     */
    public function test_the_base_currency_is_resolved_once_per_request(): void
    {
        Event::factory()->count(10)->create(['status' => 'published', 'is_free' => false, 'price_points' => 50]);

        $result = $this->countQueries(
            fn () => app(EventBrowseService::class)->browse(['per_page' => 10], null)
        );

        $baseLookups = count(array_filter(
            $result['queries'],
            fn (string $sql) => str_contains($sql, 'is_base')
        ));

        $this->assertLessThanOrEqual(1, $baseLookups, "The base currency was looked up {$baseLookups} times.");
    }

    /** Growing the page must not grow the query count in step with it. */
    public function test_doubling_the_page_does_not_double_the_queries(): void
    {
        Event::factory()->count(40)->create(['status' => 'published']);

        $ten = $this->countQueries(fn () => app(EventBrowseService::class)->browse(['per_page' => 10], null));
        $forty = $this->countQueries(fn () => app(EventBrowseService::class)->browse(['per_page' => 40], null));

        $this->assertLessThanOrEqual(
            $ten['count'] + 5,
            $forty['count'],
            "10 events took {$ten['count']} queries, 40 took {$forty['count']} — the cost is per card, not per page."
        );
    }
}
