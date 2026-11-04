<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notices written before every notice named its object one way still carry
 * the old keys in their stored `data` (only the title and body are rendered
 * at read time; `data` is kept as it was written). This rewrites them to the
 * shape every notice has now — `target_type` and `target_id` — by the same
 * rules the senders follow, and drops the old keys:
 *
 *  - a coworking session: `session_type`/`session_id` → `booking` or `walkin_session`;
 *  - a coworking arrival: `arrival_request_id` → `arrival_request`;
 *  - a payment: what it paid for — `booking`, `walkin_session`, the `event` of
 *    an `event_attendee`, `item_sale` — else the `payment` itself (a top-up, a plan);
 *  - an event's payment due (`event_id` beside the payable) → `event`;
 *  - `inquiry_id` → `inquiry`, `membership_id` → `membership`.
 *
 * A notice already naming a target, or with no `data`, is left untouched.
 * Keys it does not know stay. One way: the old keys are not restored.
 */
return new class extends Migration
{
    /** The keys notices carried before `target_type`/`target_id`. */
    private const LEGACY_KEYS = [
        'session_type', 'session_id', 'arrival_request_id',
        'payment_id', 'purpose', 'payable_type', 'payable_id', 'event_id',
        'inquiry_id', 'membership_id',
    ];

    public function up(): void
    {
        DB::table('notifications')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->data, true);

                if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
                    continue;
                }

                $converted = $this->convert($payload['data']);

                if ($converted === $payload['data']) {
                    continue;
                }

                $payload['data'] = $converted;
                DB::table('notifications')->where('id', $row->id)->update(['data' => json_encode($payload)]);
            }
        });
    }

    public function down(): void
    {
        // One way: the old keys are not rebuilt.
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function convert(array $data): array
    {
        if (isset($data['target_type']) || array_intersect_key($data, array_flip(self::LEGACY_KEYS)) === []) {
            return $data;
        }

        $target = $this->target($data);
        $rest = array_diff_key($data, array_flip(self::LEGACY_KEYS));

        return $target === null ? $rest : $target + $rest;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{target_type: string, target_id: string}|null
     */
    private function target(array $data): ?array
    {
        if (isset($data['session_type'], $data['session_id'])) {
            return $this->named($data['session_type'] === 'walkin' ? 'walkin_session' : 'booking', $data['session_id']);
        }

        if (isset($data['arrival_request_id'])) {
            return $this->named('arrival_request', $data['arrival_request_id']);
        }

        if (array_key_exists('payable_type', $data) || isset($data['payment_id'])) {
            return $this->paidFor($data);
        }

        if (isset($data['event_id'])) {
            return $this->named('event', $data['event_id']);
        }

        if (isset($data['inquiry_id'])) {
            return $this->named('inquiry', $data['inquiry_id']);
        }

        if (isset($data['membership_id'])) {
            return $this->named('membership', $data['membership_id']);
        }

        return null;
    }

    /**
     * What a payment paid for, as the payment notifier names it now.
     *
     * @param  array<string, mixed>  $data
     * @return array{target_type: string, target_id: string}|null
     */
    private function paidFor(array $data): ?array
    {
        $type = $this->alias($data['payable_type'] ?? null);
        $id = $data['payable_id'] ?? null;

        if ($id !== null && in_array($type, ['booking', 'walkin_session', 'item_sale'], true)) {
            return $this->named($type, $id);
        }

        if ($type === 'event_attendee') {
            $eventId = $data['event_id'] ?? ($id === null ? null : DB::table('event_attendees')->where('id', $id)->value('event_id'));

            if ($eventId !== null) {
                return $this->named('event', $eventId);
            }
        }

        return isset($data['payment_id']) ? $this->named('payment', $data['payment_id']) : null;
    }

    /** A payable type as its morph alias: rows written before the morph map hold the class name. */
    private function alias(mixed $type): ?string
    {
        return match ($type) {
            'booking', 'App\Domain\Booking\Models\Booking' => 'booking',
            'walkin_session', 'App\Domain\Booking\Models\WalkinSession' => 'walkin_session',
            'event_attendee', 'App\Domain\Ecosystem\Models\EventAttendee' => 'event_attendee',
            'item_sale', 'App\Domain\Retail\Models\ItemSale' => 'item_sale',
            default => null,
        };
    }

    /** @return array{target_type: string, target_id: string} */
    private function named(string $type, mixed $id): array
    {
        return ['target_type' => $type, 'target_id' => (string) $id];
    }
};
