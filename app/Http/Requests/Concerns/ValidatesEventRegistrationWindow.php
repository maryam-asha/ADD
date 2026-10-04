<?php

namespace App\Http\Requests\Concerns;

use App\Domain\Experience\Models\Event;
use App\Support\Time\LocalTime;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Validation\Validator;

/**
 * Keeps an event's registration window inside the event.
 *
 * The close may not fall after the start, and must come after the open. Each
 * end is read from what this request sends, falling back to what the event
 * already has — so moving the sessions earlier is checked against a close
 * set last week, not only a close sent alongside them.
 *
 * The start is the first sitting when sessions are sent: that is what
 * EventSchedule will derive the envelope from once the request lands.
 */
trait ValidatesEventRegistrationWindow
{
    /**
     * @return list<callable(Validator): void>
     */
    protected function registrationWindowChecks(?Event $event): array
    {
        return [function (Validator $validator) use ($event) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $start = $this->effectiveStart($event);
            $opens = $this->effectiveInstant('registration_opens_at', $event?->registration_opens_at);
            $closes = $this->effectiveInstant('registration_closes_at', $event?->registration_closes_at);

            if ($closes === null) {
                return;
            }

            if ($start !== null && $closes->isAfter($start)) {
                $validator->errors()->add('registration_closes_at', __('api.validation.registration_closes_after_start', [
                    'start' => app(LocalTime::class)->toLocal($start)->format('Y-m-d H:i'),
                ]));

                return;
            }

            if ($opens !== null && ! $closes->isAfter($opens)) {
                $validator->errors()->add('registration_closes_at', __('api.validation.registration_closes_before_it_opens'));
            }
        }];
    }

    private function effectiveStart(?Event $event): ?Carbon
    {
        $sessions = $this->input('sessions');

        if (is_array($sessions) && $sessions !== []) {
            $first = collect($sessions)->pluck('start_at')->filter()->min();

            return $first ? Carbon::parse($first) : null;
        }

        return $this->effectiveInstant('start_at', $event?->start_at);
    }

    private function effectiveInstant(string $key, mixed $stored): ?Carbon
    {
        $value = $this->exists($key) ? $this->input($key) : $stored;

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        return filled($value) ? Carbon::parse($value) : null;
    }
}
