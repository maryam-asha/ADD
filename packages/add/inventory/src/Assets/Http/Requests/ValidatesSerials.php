<?php

namespace Add\Inventory\Assets\Http\Requests;

use Add\Inventory\Assets\Models\Asset;
use Illuminate\Contracts\Validation\Validator;

/**
 * The manufacturer's serial: one per unit, never two the same for one item
 * — not in one request, and not against an asset of that item already
 * registered. Compared trimmed and regardless of case; a blank one is not
 * known yet and is never a duplicate. Different items may share a serial
 * (different makers).
 */
trait ValidatesSerials
{
    /**
     * @return array<string, list<string>>
     */
    protected function serialRules(string $prefix = ''): array
    {
        return [
            "{$prefix}serial_numbers" => ['nullable', 'array'],
            "{$prefix}serial_numbers.*" => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Each entry is a request's (or a line's) serials: `key` is where its
     * errors go — `serial_numbers` names each unit as `.{j}`, `serial_number`
     * is a single one — `item_id` whose assets it may not repeat, `count`
     * how many units it makes, and `ignore` the asset being edited.
     *
     * @param  list<array{key: string, item_id: mixed, count: int, serials: mixed, ignore?: int|null}>  $entries
     */
    protected function checkSerials(Validator $validator, array $entries): void
    {
        /** @var array<int, array<string, true>> $seen */
        $seen = [];
        /** @var array<int, array<string, list<string>>> $wanted error keys per normalised serial, per item */
        $wanted = [];
        $ignore = [];

        foreach ($entries as $entry) {
            $key = $entry['key'];
            $serials = $entry['serials'];

            if (! is_numeric($entry['item_id']) || ! is_array($serials) || $validator->errors()->has($key)) {
                continue;
            }

            if (str_ends_with($key, 'serial_numbers') && count($serials) > $entry['count']) {
                $validator->errors()->add($key, __('inventory::assets.serials_over_count', ['max' => $entry['count']]));

                continue;
            }

            $item = (int) $entry['item_id'];
            $single = ! str_ends_with($key, 'serial_numbers');

            foreach (array_values($serials) as $j => $serial) {
                $normal = is_string($serial) ? mb_strtolower(trim($serial)) : '';
                $at = $single ? $key : "{$key}.{$j}";

                if ($normal === '' || $validator->errors()->has($at)) {
                    continue;
                }

                if (isset($seen[$item][$normal])) {
                    $validator->errors()->add($at, __('inventory::assets.serial_repeated'));

                    continue;
                }

                $seen[$item][$normal] = true;
                $wanted[$item][$normal][] = $at;
            }

            if (isset($entry['ignore'])) {
                $ignore[] = $entry['ignore'];
            }
        }

        foreach ($wanted as $item => $serials) {
            $existing = Asset::query()
                ->where('item_id', $item)
                ->whereNotIn('id', $ignore)
                ->whereRaw('LOWER(TRIM(serial_number)) IN ('.implode(',', array_fill(0, count($serials), '?')).')', array_map('strval', array_keys($serials)))
                ->pluck('serial_number')
                ->map(fn (string $serial) => mb_strtolower(trim($serial)));

            foreach ($existing->unique() as $normal) {
                foreach ($serials[$normal] ?? [] as $at) {
                    $validator->errors()->add($at, __('inventory::assets.serial_taken'));
                }
            }
        }
    }
}
