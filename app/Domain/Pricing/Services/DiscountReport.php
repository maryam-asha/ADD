<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Models\Payment;
use App\Domain\Pricing\Enums\DiscountKind;
use App\Domain\Pricing\Models\AppliedDiscount;
use App\Domain\Settings\Services\SettingService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The discounts given over a period, read from the discount ledger
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md):
 * one row per operation — the booking, walk-in, registration or plan
 * payment — with every reduction and saving it carries, its status, and
 * whether it counts. Totals are over the counted ones only; savings are
 * totalled apart and never taken off the list price.
 *
 * Filters: `from`/`to` (local dates, inclusive, against when the price was
 * locked), `kind` (operations carrying one of that kind), `purpose`,
 * `status` (`counted` | `void`).
 */
class DiscountReport
{
    /** The record each column names, and the name a row gives it. */
    private const RECORDS = [
        'booking_id' => [Booking::class, 'booking'],
        'walkin_session_id' => [WalkinSession::class, 'walkin_session'],
        'event_attendee_id' => [EventAttendee::class, 'event_attendee'],
        'payment_id' => [Payment::class, 'payment'],
    ];

    private const CHUNK = 500;

    public function __construct(private readonly SettingService $settings) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(array $filters, int $perPage): LengthAwarePaginator
    {
        $page = $this->operations($filters)->orderByDesc('at')->orderByDesc('id')->paginate($perPage);
        $groups = collect($page->items());

        $records = $this->records($groups);
        $rows = $this->rowsOf($groups);
        $counted = AppliedDiscount::query()->whereKey($groups->pluck('id'))->live()->pluck('id')->flip();

        $page->setCollection($groups->map(function (object $group) use ($records, $rows, $counted) {
            [$column, $id] = $this->recordKey($group);

            return $this->present(
                $column,
                $records[$column][$id] ?? null,
                $rows[$column][$id] ?? collect(),
                $group,
                $counted->has($group->id),
            );
        }));

        return $page;
    }

    /**
     * Over the counted operations only.
     *
     * @param  array<string, mixed>  $filters
     * @return array{operations: int, list_points: int, final_points: int, points_by_kind: array<string, int>, saving_points: int, effective_percent: int}
     */
    public function totals(array $filters): array
    {
        $byKind = $this->rows($filters)->live()
            ->whereIn('kind', array_map(fn (DiscountKind $kind) => $kind->value, DiscountKind::reductions()))
            ->selectRaw('kind, SUM(points) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        $ids = array_fill_keys(array_keys(self::RECORDS), []);
        $operations = 0;

        foreach ($this->operations($filters)->live()->cursor() as $group) {
            [$column, $id] = $this->recordKey($group);
            $ids[$column][] = $id;
            $operations++;
        }

        $list = 0;
        $final = 0;

        foreach ($ids as $column => $keys) {
            [$class] = self::RECORDS[$column];
            $finalColumn = $class === Payment::class ? 'amount_points' : 'price_points';

            foreach (array_chunk($keys, self::CHUNK) as $chunk) {
                $sums = $class::query()->whereKey($chunk)
                    ->selectRaw("SUM(COALESCE(list_price_points, {$finalColumn}, 0)) as list, SUM(COALESCE({$finalColumn}, 0)) as final")
                    ->toBase()
                    ->first();
                $list += (int) $sums->list;
                $final += (int) $sums->final;
            }
        }

        return [
            'operations' => $operations,
            'list_points' => $list,
            'final_points' => $final,
            'points_by_kind' => collect(DiscountKind::reductions())
                ->mapWithKeys(fn (DiscountKind $kind) => [$kind->value => (int) ($byKind[$kind->value] ?? 0)])
                ->all(),
            'saving_points' => (int) $this->rows($filters)->live()->where('kind', DiscountKind::DurationSaving->value)->sum('points'),
            // Rounded down, like every percentage given: never overstated.
            'effective_percent' => AppliedDiscount::percentOf($list - $final, $list),
        ];
    }

    /**
     * The ledger rows the filters select. Every row of a record shares its
     * liveness, so filtering rows by status filters records.
     *
     * @param  array<string, mixed>  $filters
     */
    private function rows(array $filters): Builder
    {
        $timezone = (string) $this->settings->get('app.timezone', 'Asia/Damascus');

        return AppliedDiscount::query()
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('created_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('created_at', '<', CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc()))
            ->when($filters['purpose'] ?? null, fn (Builder $q, string $purpose) => $q->where('purpose', $purpose))
            ->when(($filters['status'] ?? null) === 'counted', fn (Builder $q) => $q->live())
            ->when(($filters['status'] ?? null) === 'void', fn (Builder $q) => $q->whereNotIn('id', AppliedDiscount::query()->live()->select('id')))
            ->when($filters['kind'] ?? null, function (Builder $q, string $kind) {
                // Operations carrying one of this kind — with all their rows.
                $q->where(function (Builder $any) use ($kind) {
                    foreach (array_keys(self::RECORDS) as $column) {
                        $any->orWhereIn($column, AppliedDiscount::query()->where('kind', $kind)->whereNotNull($column)->select($column));
                    }
                });
            });
    }

    /**
     * One group per operation: its first row's id, when it was priced.
     *
     * @param  array<string, mixed>  $filters
     */
    private function operations(array $filters): Builder
    {
        return $this->rows($filters)
            ->selectRaw('MIN(id) as id, MIN(created_at) as at, user_id, purpose, '.implode(', ', array_keys(self::RECORDS)))
            ->groupBy('user_id', 'purpose', ...array_keys(self::RECORDS));
    }

    /** @return array{0: string, 1: int} */
    private function recordKey(object $group): array
    {
        foreach (array_keys(self::RECORDS) as $column) {
            if ($group->{$column} !== null) {
                return [$column, (int) $group->{$column}];
            }
        }

        return ['booking_id', 0];
    }

    /**
     * @param  Collection<int, object>  $groups
     * @return array<string, Collection<int, Model>>
     */
    private function records(Collection $groups): array
    {
        $records = [];

        foreach (self::RECORDS as $column => [$class]) {
            $ids = $groups->pluck($column)->filter()->unique()->values();
            $records[$column] = $ids->isEmpty() ? collect() : $class::query()->with('user')->whereKey($ids)->get()->keyBy('id');
        }

        return $records;
    }

    /**
     * Every ledger row of the page's operations, whatever the filters.
     *
     * @param  Collection<int, object>  $groups
     * @return array<string, Collection<int, Collection<int, AppliedDiscount>>>
     */
    private function rowsOf(Collection $groups): array
    {
        $rows = [];

        foreach (array_keys(self::RECORDS) as $column) {
            $ids = $groups->pluck($column)->filter()->unique()->values();
            $rows[$column] = $ids->isEmpty()
                ? collect()
                : AppliedDiscount::query()->whereIn($column, $ids)->orderBy('id')->get()->groupBy($column);
        }

        return $rows;
    }

    /**
     * @param  Collection<int, AppliedDiscount>  $rows
     * @return array<string, mixed>
     */
    private function present(string $column, ?Model $record, Collection $rows, object $group, bool $counted): array
    {
        [, $type] = self::RECORDS[$column];
        $saving = $rows->first(fn (AppliedDiscount $row) => $row->kind === DiscountKind::DurationSaving);
        $final = $record instanceof Payment ? $record->amount_points : $record?->price_points;

        return [
            'record' => [
                'type' => $type,
                'id' => (int) $group->{$column},
                'reference' => $record?->reference,
            ],
            'member' => $record?->user !== null
                ? ['id' => $record->user->id, 'name' => $record->user->name, 'phone' => $record->user->phone]
                : null,
            'purpose' => $group->purpose,
            'at' => CarbonImmutable::parse($group->at, 'UTC')->toIso8601ZuluString(),
            'status' => $this->status($record),
            'counted' => $counted,
            'list_points' => $record !== null ? (int) ($record->list_price_points ?? $final) : null,
            'final_points' => $final !== null ? (int) $final : null,
            'discounts' => $rows
                ->reject(fn (AppliedDiscount $row) => $row->kind === DiscountKind::DurationSaving)
                ->map(fn (AppliedDiscount $row) => [
                    'kind' => $row->kind->value,
                    'name' => $this->translated($row->terms['name'] ?? null),
                    'code' => $row->terms['code'] ?? null,
                    'points' => $row->points,
                    'percent' => $row->percent,
                ])
                ->values()
                ->all(),
            'saving' => $saving !== null ? [
                'points' => $saving->points,
                'percent' => $saving->percent,
                'reference_name' => $saving->terms['reference_name'] ?? null,
                'units' => $saving->terms['units'] ?? null,
            ] : null,
        ];
    }

    /** The record's own status; a walk-in, which has none, its payment state. */
    private function status(?Model $record): ?string
    {
        $status = match (true) {
            $record === null => null,
            $record instanceof WalkinSession => $record->payment_state,
            default => $record->status,
        };

        return $status instanceof BackedEnum ? (string) $status->value : $status;
    }

    private function translated(mixed $name): ?string
    {
        if (! is_array($name)) {
            return $name;
        }

        return $name[app()->getLocale()] ?? $name['en'] ?? $name['ar'] ?? null;
    }
}
