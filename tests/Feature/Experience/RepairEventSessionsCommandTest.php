<?php

namespace Tests\Feature\Experience;

use App\Domain\Experience\Models\Event;
use App\Support\Time\LocalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sessions backfill copied each event's envelope into a single session,
 * so a four-day course running 10:00–14:00 became one 76-hour sitting. The
 * repair command splits those back into one sitting per day, and gives an
 * event with no sessions at all the one its envelope describes.
 */
class RepairEventSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function utc(string $local): string
    {
        return app(LocalTime::class)->toUtc($local)->toDateTimeString();
    }

    /** @param  list<array{0: string, 1: string}>  $sessions  local wall clocks */
    private function event(string $start, string $end, array $sessions): Event
    {
        $event = Event::create([
            'title' => ['en' => 'Course', 'ar' => 'دورة'],
            'start_at' => $this->utc($start),
            'end_at' => $this->utc($end),
            'location' => 'Hall',
            'status' => 'published',
        ]);

        foreach ($sessions as [$from, $until]) {
            $event->sessions()->create(['start_at' => $this->utc($from), 'end_at' => $this->utc($until)]);
        }

        return $event;
    }

    /** @return list<array{0: string, 1: string}> local wall clocks */
    private function sittings(Event $event): array
    {
        $time = app(LocalTime::class);

        return $event->sessions()->get()->map(fn ($s) => [
            $time->toLocal($s->start_at)->format('Y-m-d H:i'),
            $time->toLocal($s->end_at)->format('Y-m-d H:i'),
        ])->all();
    }

    public function test_a_multi_day_block_with_daily_hours_is_split_into_one_sitting_a_day(): void
    {
        $event = $this->event('2026-11-01 10:00', '2026-11-04 14:00', [['2026-11-01 10:00', '2026-11-04 14:00']]);

        $this->artisan('events:repair-sessions', ['--apply' => true])->assertSuccessful();

        $this->assertSame([
            ['2026-11-01 10:00', '2026-11-01 14:00'],
            ['2026-11-02 10:00', '2026-11-02 14:00'],
            ['2026-11-03 10:00', '2026-11-03 14:00'],
            ['2026-11-04 10:00', '2026-11-04 14:00'],
        ], $this->sittings($event));

        // The envelope is unchanged: first start, last end.
        $event->refresh();
        $this->assertSame($this->utc('2026-11-01 10:00'), $event->start_at->toDateTimeString());
        $this->assertSame($this->utc('2026-11-04 14:00'), $event->end_at->toDateTimeString());
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $event = $this->event('2026-11-01 10:00', '2026-11-04 14:00', [['2026-11-01 10:00', '2026-11-04 14:00']]);

        $this->artisan('events:repair-sessions')->assertSuccessful();

        $this->assertCount(1, $this->sittings($event));
    }

    public function test_a_genuinely_continuous_event_is_left_alone(): void
    {
        // A hackathon: Friday 18:00 straight through to Sunday 18:00.
        $event = $this->event('2026-11-06 18:00', '2026-11-08 18:00', [['2026-11-06 18:00', '2026-11-08 18:00']]);

        $this->artisan('events:repair-sessions', ['--apply' => true])->assertSuccessful();

        $this->assertSame([['2026-11-06 18:00', '2026-11-08 18:00']], $this->sittings($event));
    }

    public function test_an_event_already_scheduled_by_day_is_left_alone(): void
    {
        $event = $this->event('2026-11-01 10:00', '2026-11-02 14:00', [
            ['2026-11-01 10:00', '2026-11-01 14:00'],
            ['2026-11-02 10:00', '2026-11-02 14:00'],
        ]);

        $this->artisan('events:repair-sessions', ['--apply' => true])->assertSuccessful();

        $this->assertCount(2, $this->sittings($event));
    }

    public function test_an_event_with_no_sessions_gets_the_one_its_envelope_describes(): void
    {
        $event = $this->event('2026-11-01 10:00', '2026-11-01 12:00', []);

        $this->artisan('events:repair-sessions', ['--apply' => true])->assertSuccessful();

        $this->assertSame([['2026-11-01 10:00', '2026-11-01 12:00']], $this->sittings($event));
    }
}
