<?php

namespace App\Support\Presenters;

use App\Support\Time\LocalTime;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns a pair of local Carbon instants into the structured date/time objects
 * the API returns to members and the dashboard.
 *
 * Output shape
 * ─────────────────────────────────────────────────────────────────────────────
 * date:
 *   month       – short month name ("SEP")
 *   day         – zero-padded day  ("10")
 *   weekday     – short weekday    ("THU")
 *   formatted   – long label       ("September 10, 2026")
 *   end_month   – (multi-day only) short month name of the end date
 *   end_day     – (multi-day only) zero-padded end day
 *   end_weekday – (multi-day only) short weekday of the end date
 *   end_formatted – (multi-day only) long label of the end date
 *
 * time:
 *   start  – "04:00 AM"
 *   end    – "05:30 AM"
 *   range  – "04:00 AM – 05:30 AM"
 */
class EventDatePresenter
{
    /**
     * Build the `date` and `time` objects from a pair of local Carbon instants.
     *
     * @return array{date: array<string, string>, time: array<string, string>}
     */
    public function present(?Carbon $localStart, ?Carbon $localEnd): array
    {
        if ($localStart === null) {
            return ['date' => null, 'time' => null];
        }

        $isMultiDay = $localEnd !== null
            && $localStart->toDateString() !== $localEnd->toDateString();

        $date = [
            'month' => strtoupper($localStart->format('M')),
            'day' => $localStart->format('d'),
            'weekday' => strtoupper($localStart->format('D')),
            'formatted' => $localStart->format('F j, Y'),
        ];

        if ($isMultiDay) {
            $date['end_month'] = strtoupper($localEnd->format('M'));
            $date['end_day'] = $localEnd->format('d');
            $date['end_weekday'] = strtoupper($localEnd->format('D'));
            $date['end_formatted'] = $localEnd->format('F j, Y');
        }

        $startLabel = $localStart->format('h:i A');
        $endLabel = $localEnd?->format('h:i A');

        $time = [
            'start' => $startLabel,
            'end' => $endLabel,
            'range' => $endLabel ? "{$startLabel} – {$endLabel}" : $startLabel,
        ];

        return compact('date', 'time');
    }

    /**
     * Analyze a collection of sessions or event envelope instants to return:
     * - schedule: list of date/time objects with date, start_time, end_time, etc.
     * - is_same_time: true if all sessions share the exact same start and end times.
     * - is_continuous: true if calendar dates are consecutive without any day gaps.
     *
     * @param  Collection<int, mixed>|array  $sessions
     * @return array{schedule: list<array<string, mixed>>, is_same_time: bool, is_continuous: bool}
     */
    public function analyzeSchedule($sessions, ?Carbon $localStart = null, ?Carbon $localEnd = null): array
    {
        $timeService = app(LocalTime::class);
        $items = collect();
        $sessionCollection = collect($sessions);

        if ($sessionCollection->isNotEmpty()) {
            foreach ($sessionCollection as $session) {
                $startAt = is_array($session) ? ($session['start_at'] ?? null) : $session->start_at;
                $endAt = is_array($session) ? ($session['end_at'] ?? null) : $session->end_at;
                $id = is_array($session) ? ($session['id'] ?? null) : ($session->id ?? null);
                $title = is_array($session) ? ($session['title'] ?? null) : ($session->title ?? null);

                $from = $timeService->toLocal($startAt);
                $until = $timeService->toLocal($endAt);

                if ($from) {
                    $startFormatted = $from->format('h:i A');
                    $endFormatted = $until ? $until->format('h:i A') : null;

                    $items->push([
                        'id' => $id,
                        'title' => $title,
                        'date' => $from->format('Y-m-d'),
                        'date_formatted' => $from->format('F j, Y'),
                        'weekday' => strtoupper($from->format('D')),
                        'month' => strtoupper($from->format('M')),
                        'day' => $from->format('d'),
                        'start_time' => $startFormatted,
                        'end_time' => $endFormatted,
                        'start_time_24' => $from->format('H:i'),
                        'end_time_24' => $until?->format('H:i'),
                        'range' => $from && $until ? "{$startFormatted} – {$endFormatted}" : $startFormatted,
                        'ends_next_day' => $from && $until && $until->toDateString() !== $from->toDateString(),
                    ]);
                }
            }
        } elseif ($localStart) {
            $startFormatted = $localStart->format('h:i A');
            $endFormatted = $localEnd ? $localEnd->format('h:i A') : null;

            $items->push([
                'id' => null,
                'title' => null,
                'date' => $localStart->format('Y-m-d'),
                'date_formatted' => $localStart->format('F j, Y'),
                'weekday' => strtoupper($localStart->format('D')),
                'month' => strtoupper($localStart->format('M')),
                'day' => $localStart->format('d'),
                'start_time' => $startFormatted,
                'end_time' => $endFormatted,
                'start_time_24' => $localStart->format('H:i'),
                'end_time_24' => $localEnd ? $localEnd->format('H:i') : null,
                'range' => $localStart && $localEnd ? "{$startFormatted} – {$endFormatted}" : $startFormatted,
                'ends_next_day' => $localStart && $localEnd && $localEnd->toDateString() !== $localStart->toDateString(),
            ]);
        }

        // Sort items strictly in ascending order by date and start time
        $items = $items->sortBy(function ($item) {
            return $item['date'].' '.($item['start_time_24'] ?? '00:00');
        })->values();

        // is_same_time: are the times identical across all sessions?
        $isSameTime = true;
        if ($items->count() > 1) {
            $firstStart = $items->first()['start_time_24'];
            $firstEnd = $items->first()['end_time_24'];
            foreach ($items as $item) {
                if ($item['start_time_24'] !== $firstStart || $item['end_time_24'] !== $firstEnd) {
                    $isSameTime = false;
                    break;
                }
            }
        }

        // is_continuous: are the dates consecutive without gaps?
        $isContinuous = true;
        if ($items->count() > 1) {
            $dates = $items->pluck('date')->unique()->sort()->values();
            for ($i = 1; $i < $dates->count(); $i++) {
                $prev = Carbon::parse($dates[$i - 1])->startOfDay();
                $curr = Carbon::parse($dates[$i])->startOfDay();
                if ($prev->copy()->addDay()->toDateString() !== $curr->toDateString()) {
                    $isContinuous = false;
                    break;
                }
            }
        }

        // Return strictly date, start_time, and end_time in schedule
        $cleanSchedule = $items->map(fn ($item) => [
            'date' => $item['date'],
            'start_time' => $item['start_time'],
            'end_time' => $item['end_time'],
        ])->values()->all();

        return [
            'schedule' => $cleanSchedule,
            'is_same_time' => $isSameTime,
            'is_continuous' => $isContinuous,
        ];
    }
}
