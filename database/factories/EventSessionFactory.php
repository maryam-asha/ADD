<?php

namespace Database\Factories;

use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Models\EventSession;
use App\Support\Time\LocalTime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventSession>
 */
class EventSessionFactory extends Factory
{
    protected $model = EventSession::class;

    public function definition(): array
    {
        $start = now()->addDays(7)->setTime(9, 0);

        return [
            'event_id' => Event::factory(),
            'start_at' => $start,
            'end_at' => $start->copy()->addHours(8),
            'title' => null,
        ];
    }

    /**
     * A sitting on a given day, at given wall-clock hours.
     *
     * The hours are the district's, interpreted the way the Form Request
     * interprets what an admin types. Writing the bare string would store
     * 09:00 as 09:00 UTC and read it back as 12:00 local — the same
     * three-hour drift this application has shipped twice.
     */
    public function on(string $date, string $from, string $until): static
    {
        $local = app(LocalTime::class);

        return $this->state(fn () => [
            'start_at' => $local->toUtc("{$date} {$from}:00"),
            'end_at' => $local->toUtc("{$date} {$until}:00"),
        ]);
    }
}
